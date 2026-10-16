<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Guest;
use App\Support\AuditLogger;
use App\Support\ContactNormalizer;
use App\Support\PageSize;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Account-scoped contact management.
 *
 * A contact is a reusable identity shared by event guests; it never owns event
 * data. Editing a contact changes only the contact (name/email/phone/notes) and
 * never rewrites linked guests, and deleting a contact detaches its guests
 * (the nullable `guests.contact_id` uses nullOnDelete) without deleting them.
 */
class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $user = $request->user();
        $ownerId = $user->isStaff() ? $user->owner_id : $user->id;

        $query = Contact::query()
            ->where('user_id', $ownerId)
            ->withCount('guests');

        if ($request->filled('search')) {
            $term = trim($request->string('search')->toString());
            $like = '%'.$term.'%';
            $emailKey = ContactNormalizer::email($term);
            $phoneKey = ContactNormalizer::phone($term);

            $query->where(function ($q) use ($like, $emailKey, $phoneKey) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);

                // Also match on the normalized keys so pasted values with
                // different case or punctuation still resolve exactly.
                if ($emailKey !== null) {
                    $q->orWhere('normalized_email', $emailKey);
                }
                if ($phoneKey !== null) {
                    $q->orWhere('normalized_phone', $phoneKey);
                }
            });
        }

        $contacts = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(PageSize::resolve($request));

        $contacts->through(fn (Contact $contact) => $this->contactPayload($contact));

        return response()->json($contacts);
    }

    public function show(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);

        $contact->loadCount('guests');

        return response()->json($this->contactPayload($contact));
    }

    public function guests(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);

        $guests = $contact->guests()
            // Defensive scoping: only guests whose event belongs to the same
            // account as the contact may ever be returned.
            ->whereHas('event', fn ($q) => $q->where('user_id', $contact->user_id))
            ->with('event:id,title,event_start')
            ->orderBy('id')
            ->paginate(PageSize::resolve($request));

        $guests->through(fn (Guest $guest) => [
            'id' => $guest->id,
            'name' => $guest->name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'status' => $guest->status,
            'sent_at' => $guest->sent_at?->toIso8601String(),
            'reminded_at' => $guest->reminded_at?->toIso8601String(),
            'event' => $guest->event ? [
                'id' => $guest->event->id,
                'title' => $guest->event->title,
                'event_start' => $guest->event->event_start?->toIso8601String(),
            ] : null,
        ]);

        return response()->json($guests);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('update', $contact);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Ownership and relationship identifiers are never client-controlled.
        $emailKey = array_key_exists('email', $data)
            ? ContactNormalizer::email($data['email'])
            : $contact->normalized_email;
        $phoneKey = array_key_exists('phone', $data)
            ? ContactNormalizer::phone($data['phone'])
            : $contact->normalized_phone;

        $this->assertIdentifiersAvailable($contact, $emailKey, $phoneKey);

        try {
            DB::transaction(function () use ($contact, $data, $emailKey, $phoneKey, $request) {
                if (array_key_exists('name', $data)) {
                    $contact->name = $data['name'];
                }
                if (array_key_exists('email', $data)) {
                    $contact->email = $this->cleanOptional($data['email']);
                }
                if (array_key_exists('phone', $data)) {
                    $contact->phone = $this->cleanOptional($data['phone']);
                }
                if (array_key_exists('notes', $data)) {
                    $contact->notes = $this->cleanOptional($data['notes']);
                }

                $contact->normalized_email = $emailKey;
                $contact->normalized_phone = $phoneKey;
                $contact->save();

                // Logged inside the transaction: if the audit write fails the
                // contact change rolls back (no partially applied mutation).
                AuditLogger::log($request, 'contacts.update', $contact);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'email' => ['These contact details are already in use by another contact.'],
                ]);
            }

            throw $e;
        }

        $contact->loadCount('guests');

        return response()->json($this->contactPayload($contact));
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $detached = $contact->guests()->count();

        DB::transaction(function () use ($contact, $request, $detached) {
            // Detach explicitly for portability; the foreign key is also
            // nullOnDelete. Guests, RSVPs, deliveries and tokens are untouched.
            $contact->guests()->update(['contact_id' => null]);
            $contact->delete();

            AuditLogger::log($request, 'contacts.delete', $contact, ['detached_guests' => $detached]);
        });

        return response()->json(['deleted' => true, 'detached_guests' => $detached]);
    }

    /**
     * Ensure the new normalized identifiers are free within the account,
     * excluding this contact. Returns a 422 with clear field errors otherwise.
     */
    private function assertIdentifiersAvailable(Contact $contact, ?string $emailKey, ?string $phoneKey): void
    {
        $errors = [];

        if ($emailKey !== null) {
            $taken = Contact::query()
                ->where('user_id', $contact->user_id)
                ->where('normalized_email', $emailKey)
                ->whereKeyNot($contact->getKey())
                ->exists();

            if ($taken) {
                $errors['email'] = ['Another contact in this account already uses this email.'];
            }
        }

        if ($phoneKey !== null) {
            $taken = Contact::query()
                ->where('user_id', $contact->user_id)
                ->where('normalized_phone', $phoneKey)
                ->whereKeyNot($contact->getKey())
                ->exists();

            if ($taken) {
                $errors['phone'] = ['Another contact in this account already uses this phone.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
    }

    private function cleanOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'notes' => $contact->notes,
            'guests_count' => (int) ($contact->guests_count ?? 0),
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }
}
