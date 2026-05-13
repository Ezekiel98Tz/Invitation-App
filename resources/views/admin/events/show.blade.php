@php
    $settings = (array) ($event->settings ?? []);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $event->title }}</h2>
            <div class="mt-1 text-sm text-gray-600">
                {{ $event->event_start?->timezone($event->timezone)->toDayDateTimeString() }} ({{ $event->timezone }})
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div
                x-data="eventManage({
                    eventId: {{ $event->id }},
                    event: @js($event),
                })"
                x-init="init()"
                class="space-y-6"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('dashboard', absolute: false) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Back</a>
                    <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="openEdit()">Edit</button>
                    @if(auth()->user()->isAdmin() && auth()->id() === $event->user_id)
                        <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="duplicate()">Duplicate</button>
                        <button type="button" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700" x-on:click="destroy()">Delete</button>
                    @endif
                </div>
                <div x-show="toast.show" x-cloak class="rounded-md bg-green-50 p-3 text-sm text-green-700" x-text="toast.message"></div>
                <div x-show="error" x-cloak class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="error"></div>

                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex gap-6 px-6">
                            <button type="button" class="border-b-2 py-4 text-sm font-medium" :class="tab === 'guests' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'" x-on:click="tab='guests'">Guests</button>
                            <button type="button" class="border-b-2 py-4 text-sm font-medium" :class="tab === 'invitations' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'" x-on:click="tab='invitations'">Invitations</button>
                            <button type="button" class="border-b-2 py-4 text-sm font-medium" :class="tab === 'deliveries' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'" x-on:click="tab='deliveries'">Deliveries</button>
                        </nav>
                    </div>

                    <div class="p-6">
                        <div x-show="tab === 'guests'" x-cloak class="space-y-6">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
                                    <div>
                                        <x-input-label for="search" value="Search" />
                                        <x-text-input id="search" class="mt-1 block w-full" type="text" placeholder="Name or email" x-model="guests.filters.search" x-on:keydown.enter.prevent="loadGuests(1)" />
                                    </div>
                                    <div>
                                        <x-input-label for="status" value="Status" />
                                        <select id="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" x-model="guests.filters.status" x-on:change="loadGuests(1)">
                                            <option value="">All</option>
                                            <option value="pending">pending</option>
                                            <option value="accepted">accepted</option>
                                            <option value="declined">declined</option>
                                        </select>
                                    </div>
                                    <div>
                                        <button type="button" class="w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="loadGuests(1)">Apply</button>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700" x-on:click="openImportWizard()">Import</button>
                                    <a :href="exportGuestsUrl" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Export</a>
                                    <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="sendReminders()">Send reminders</button>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attending</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <template x-for="g in guests.items" :key="g.id">
                                            <tr>
                                                <td class="px-4 py-3 text-sm text-gray-900" x-text="g.name"></td>
                                                <td class="px-4 py-3 text-sm text-gray-600" x-text="g.email"></td>
                                                <td class="px-4 py-3 text-sm text-gray-600" x-text="g.phone"></td>
                                                <td class="px-4 py-3 text-sm text-gray-600" x-text="g.status"></td>
                                                <td class="px-4 py-3 text-sm text-gray-600" x-text="g.rsvp ? g.rsvp.attending_count : ''"></td>
                                                <td class="px-4 py-3 text-sm text-right">
                                                    <button type="button" class="text-indigo-600 hover:text-indigo-900" x-on:click="resend(g)">Resend</button>
                                                </td>
                                            </tr>
                                        </template>
                                        <tr x-show="!guests.loading && guests.items.length === 0" x-cloak>
                                            <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-600">No guests found.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex items-center justify-between text-sm text-gray-600" x-show="guests.pagination" x-cloak>
                                <div>
                                    Page <span class="font-medium" x-text="guests.pagination.current_page"></span> of <span class="font-medium" x-text="guests.pagination.last_page"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50" :disabled="!guests.pagination.prev_page_url" x-on:click="loadGuests(guests.pagination.current_page - 1)">Prev</button>
                                    <button type="button" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50" :disabled="!guests.pagination.next_page_url" x-on:click="loadGuests(guests.pagination.current_page + 1)">Next</button>
                                </div>
                            </div>
                        </div>

                        <div x-show="tab === 'invitations'" x-cloak class="space-y-6">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50" :disabled="invites.sending" x-on:click="sendInvitations()" x-text="invites.sending ? 'Sending...' : 'Send invitations'"></button>
                                <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="loadPreview()">Load preview</button>
                            </div>

                            <div class="rounded-md border border-gray-200 p-4" x-show="invites.preview" x-cloak>
                                <div class="text-sm font-medium text-gray-900">Preview</div>
                                <div class="mt-3 space-y-4 text-sm text-gray-700">
                                    <template x-if="invites.preview.mail">
                                        <div class="rounded-md bg-gray-50 p-3">
                                            <div class="font-medium">Email</div>
                                            <div class="mt-1 text-gray-600" x-text="invites.preview.mail.subject"></div>
                                            <ul class="mt-2 list-disc ps-5 text-gray-600">
                                                <template x-for="line in invites.preview.mail.lines" :key="line">
                                                    <li x-text="line"></li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>

                                    <template x-if="invites.preview.sms">
                                        <div class="rounded-md bg-gray-50 p-3">
                                            <div class="font-medium">SMS</div>
                                            <div class="mt-1 text-gray-600" x-text="invites.preview.sms.message"></div>
                                        </div>
                                    </template>

                                    <template x-if="invites.preview.whatsapp">
                                        <div class="rounded-md bg-gray-50 p-3">
                                            <div class="font-medium">WhatsApp</div>
                                            <div class="mt-1 text-gray-600" x-text="invites.preview.whatsapp.message"></div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <div x-show="tab === 'deliveries'" x-cloak class="space-y-6">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="loadFailed(1)">Refresh failed</button>
                                <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50" :disabled="deliveries.retryingAll" x-on:click="retryAllFailed()" x-text="deliveries.retryingAll ? 'Queueing...' : 'Retry all failed'"></button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kind</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Channel</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Error</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <template x-for="d in deliveries.failedItems" :key="d.id">
                                            <tr>
                                                <td class="px-4 py-3 text-sm text-gray-900" x-text="d.guest ? d.guest.name : ''"></td>
                                                <td class="px-4 py-3 text-sm text-gray-600" x-text="d.kind"></td>
                                                <td class="px-4 py-3 text-sm text-gray-600" x-text="d.channel"></td>
                                                <td class="px-4 py-3 text-sm text-gray-600" x-text="d.error"></td>
                                                <td class="px-4 py-3 text-sm text-right">
                                                    <button type="button" class="text-indigo-600 hover:text-indigo-900" x-on:click="retryDelivery(d)">Retry</button>
                                                </td>
                                            </tr>
                                        </template>
                                        <tr x-show="!deliveries.loading && deliveries.failedItems.length === 0" x-cloak>
                                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-600">No failed deliveries.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex items-center justify-between text-sm text-gray-600" x-show="deliveries.pagination" x-cloak>
                                <div>
                                    Page <span class="font-medium" x-text="deliveries.pagination.current_page"></span> of <span class="font-medium" x-text="deliveries.pagination.last_page"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50" :disabled="!deliveries.pagination.prev_page_url" x-on:click="loadFailed(deliveries.pagination.current_page - 1)">Prev</button>
                                    <button type="button" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50" :disabled="!deliveries.pagination.next_page_url" x-on:click="loadFailed(deliveries.pagination.current_page + 1)">Next</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <x-modal name="event-edit" :show="false" maxWidth="2xl" focusable>
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Edit event</h2>

                        <div class="mt-4 grid grid-cols-1 gap-4">
                            <div>
                                <x-input-label for="title" value="Title" />
                                <x-text-input id="title" class="mt-1 block w-full" type="text" x-model="editor.form.title" />
                                <div class="mt-1 text-sm text-red-600" x-show="editor.errors.title" x-text="editor.errors.title?.[0]" x-cloak></div>
                            </div>

                            <div>
                                <x-input-label for="venue" value="Venue" />
                                <x-text-input id="venue" class="mt-1 block w-full" type="text" x-model="editor.form.venue" />
                                <div class="mt-1 text-sm text-red-600" x-show="editor.errors.venue" x-text="editor.errors.venue?.[0]" x-cloak></div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="event_start" value="Event start" />
                                    <x-text-input id="event_start" class="mt-1 block w-full" type="datetime-local" x-model="editor.form.event_start" />
                                    <div class="mt-1 text-sm text-red-600" x-show="editor.errors.event_start" x-text="editor.errors.event_start?.[0]" x-cloak></div>
                                </div>

                                <div>
                                    <x-input-label for="timezone" value="Timezone" />
                                    <x-text-input id="timezone" class="mt-1 block w-full" type="text" x-model="editor.form.timezone" />
                                    <div class="mt-1 text-sm text-red-600" x-show="editor.errors.timezone" x-text="editor.errors.timezone?.[0]" x-cloak></div>
                                </div>
                            </div>

                            <div>
                                <x-input-label for="capacity" value="Capacity (optional)" />
                                <x-text-input id="capacity" class="mt-1 block w-full" type="number" min="1" x-model.number="editor.form.capacity" />
                                <div class="mt-1 text-sm text-red-600" x-show="editor.errors.capacity" x-text="editor.errors.capacity?.[0]" x-cloak></div>
                            </div>

                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" x-model="editor.form.allow_plus_ones">
                                <span>Allow plus-ones</span>
                            </label>

                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" x-model="editor.form.enable_waitlist">
                                <span>Enable waitlist</span>
                            </label>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3">
                            <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="closeEdit()" :disabled="editor.saving">Cancel</button>
                            <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50" x-on:click="saveEvent()" :disabled="editor.saving" x-text="editor.saving ? 'Saving...' : 'Save'"></button>
                        </div>
                    </div>
                </x-modal>

                <x-modal name="import-wizard" :show="false" maxWidth="2xl" focusable>
                    <div class="p-6 space-y-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">Import guests</h2>
                                <p class="mt-1 text-sm text-gray-600">Upload a CSV/XLSX and map columns to name/email/phone.</p>
                            </div>
                            <button type="button" class="text-sm text-gray-600 hover:text-gray-900" x-on:click="closeImportWizard()">Close</button>
                        </div>

                        <div class="flex items-center gap-3 text-sm">
                            <div class="flex items-center gap-2">
                                <div class="h-6 w-6 rounded-full flex items-center justify-center text-white" :class="importWizard.step >= 1 ? 'bg-indigo-600' : 'bg-gray-300'">1</div>
                                <div class="text-gray-700">File</div>
                            </div>
                            <div class="h-px flex-1 bg-gray-200"></div>
                            <div class="flex items-center gap-2">
                                <div class="h-6 w-6 rounded-full flex items-center justify-center text-white" :class="importWizard.step >= 2 ? 'bg-indigo-600' : 'bg-gray-300'">2</div>
                                <div class="text-gray-700">Mapping</div>
                            </div>
                            <div class="h-px flex-1 bg-gray-200"></div>
                            <div class="flex items-center gap-2">
                                <div class="h-6 w-6 rounded-full flex items-center justify-center text-white" :class="importWizard.step >= 3 ? 'bg-indigo-600' : 'bg-gray-300'">3</div>
                                <div class="text-gray-700">Upload</div>
                            </div>
                        </div>

                        <div x-show="importWizard.error" x-cloak class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="importWizard.error"></div>

                        <div x-show="importWizard.step === 1" x-cloak class="space-y-4">
                            <div>
                                <x-input-label for="import_file" value="File" />
                                <input id="import_file" type="file" class="mt-1 block w-full text-sm text-gray-700" x-on:change="onImportFileSelected($event)">
                                <div class="mt-2 text-sm text-gray-600" x-show="importWizard.filename" x-cloak>
                                    Selected: <span class="font-medium" x-text="importWizard.filename"></span>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-3">
                                <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50" :disabled="!importWizard.file" x-on:click="nextImportStep()">Next</button>
                            </div>
                        </div>

                        <div x-show="importWizard.step === 2" x-cloak class="space-y-4">
                            <div class="text-sm text-gray-700">Choose which columns correspond to name/email/phone.</div>

                            <template x-if="importWizard.headers.length">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <x-input-label value="Name column" />
                                        <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" x-model="importWizard.mapping.name">
                                            <template x-for="h in importWizard.headers" :key="h">
                                                <option :value="h" x-text="h"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label value="Email column" />
                                        <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" x-model="importWizard.mapping.email">
                                            <option value="">(none)</option>
                                            <template x-for="h in importWizard.headers" :key="h">
                                                <option :value="h" x-text="h"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label value="Phone column" />
                                        <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" x-model="importWizard.mapping.phone">
                                            <option value="">(none)</option>
                                            <template x-for="h in importWizard.headers" :key="h">
                                                <option :value="h" x-text="h"></option>
                                            </template>
                                        </select>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!importWizard.headers.length">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <x-input-label value="Name column name" />
                                        <x-text-input class="mt-1 block w-full" type="text" x-model="importWizard.mapping.name" />
                                    </div>
                                    <div>
                                        <x-input-label value="Email column name" />
                                        <x-text-input class="mt-1 block w-full" type="text" x-model="importWizard.mapping.email" />
                                    </div>
                                    <div>
                                        <x-input-label value="Phone column name" />
                                        <x-text-input class="mt-1 block w-full" type="text" x-model="importWizard.mapping.phone" />
                                    </div>
                                </div>
                            </template>

                            <div x-show="importWizard.sampleRows.length" x-cloak class="rounded-md border border-gray-200 p-3">
                                <div class="text-sm font-medium text-gray-900">Preview</div>
                                <div class="mt-2 overflow-x-auto">
                                    <table class="min-w-full text-sm text-gray-700">
                                        <tbody>
                                            <template x-for="(row, idx) in importWizard.sampleRows" :key="idx">
                                                <tr class="border-t border-gray-100">
                                                    <template x-for="h in importWizard.headers" :key="h">
                                                        <td class="px-2 py-1 whitespace-nowrap" x-text="row[h] ?? ''"></td>
                                                    </template>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="prevImportStep()">Back</button>
                                <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50" :disabled="!importWizard.mapping.name" x-on:click="nextImportStep()">Next</button>
                            </div>
                        </div>

                        <div x-show="importWizard.step === 3" x-cloak class="space-y-4">
                            <div class="text-sm text-gray-700">
                                Uploading and processing happens in the background. You will receive an email when the import finishes.
                            </div>

                            <div class="rounded-md border border-gray-200 p-4 space-y-3">
                                <div class="flex items-center justify-between text-sm">
                                    <div class="font-medium text-gray-900" x-text="importWizard.filename"></div>
                                    <div class="text-gray-600" x-show="importWizard.uploading" x-cloak x-text="importWizard.uploadProgress + '%'"></div>
                                </div>
                                <div class="h-2 w-full rounded bg-gray-100 overflow-hidden">
                                    <div class="h-full bg-indigo-600" :style="'width: ' + importWizard.uploadProgress + '%'"></div>
                                </div>
                                <div class="text-sm text-green-700" x-show="importWizard.queued" x-cloak>Import queued.</div>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="prevImportStep()" :disabled="importWizard.uploading">Back</button>
                                <div class="flex items-center gap-3">
                                    <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="closeImportWizard()">Close</button>
                                    <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50" :disabled="importWizard.uploading || importWizard.queued" x-on:click="uploadImport()" x-text="importWizard.uploading ? 'Uploading...' : (importWizard.queued ? 'Queued' : 'Start upload')"></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-modal>

                <x-modal name="confirm-action" :show="false" maxWidth="lg" focusable>
                    <div class="p-6 space-y-4">
                        <h2 class="text-lg font-medium text-gray-900" x-text="confirm.title"></h2>
                        <p class="text-sm text-gray-700" x-text="confirm.message"></p>
                        <div class="flex items-center justify-end gap-3">
                            <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" x-on:click="closeConfirm()" :disabled="confirm.busy">Cancel</button>
                            <button type="button" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700 disabled:opacity-50" x-on:click="confirmProceed()" :disabled="confirm.busy" x-text="confirm.busy ? 'Working...' : confirm.confirmText"></button>
                        </div>
                    </div>
                </x-modal>
            </div>
        </div>
    </div>

    <script>
        function eventManage(config) {
            return {
                tab: 'guests',
                eventId: config.eventId,
                event: config.event,
                toast: { show: false, message: '' },
                error: null,
                guests: {
                    items: [],
                    pagination: null,
                    loading: false,
                    filters: { search: '', status: '' },
                },
                importWizard: {
                    step: 1,
                    file: null,
                    filename: '',
                    headers: [],
                    sampleRows: [],
                    mapping: { name: 'name', email: 'email', phone: 'phone' },
                    uploading: false,
                    uploadProgress: 0,
                    queued: false,
                    error: null,
                },
                confirm: {
                    action: null,
                    payload: null,
                    title: '',
                    message: '',
                    confirmText: 'Confirm',
                    busy: false,
                },
                invites: {
                    preview: null,
                    sending: false,
                },
                deliveries: {
                    failedItems: [],
                    pagination: null,
                    loading: false,
                    retryingAll: false,
                },
                editor: {
                    saving: false,
                    errors: {},
                    form: {
                        title: config.event.title,
                        venue: config.event.venue,
                        event_start: '',
                        timezone: config.event.timezone || 'Africa/Dar_es_Salaam',
                        capacity: config.event.capacity || null,
                        allow_plus_ones: !!(config.event.settings && config.event.settings.allow_plus_ones),
                        enable_waitlist: !!(config.event.settings && config.event.settings.enable_waitlist),
                    },
                },
                get exportGuestsUrl() {
                    return '/admin/events/' + this.eventId + '/guests/export'
                },
                init() {
                    this.editor.form.event_start = this.toDatetimeLocal(this.event.event_start)
                    this.loadGuests(1)
                    this.loadPreview()
                    this.loadFailed(1)
                },
                toDatetimeLocal(value) {
                    if (!value) return ''
                    const d = new Date(value.replace(' ', 'T'))
                    if (isNaN(d.getTime())) return ''
                    const pad = n => String(n).padStart(2, '0')
                    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
                },
                notify(message) {
                    this.toast = { show: true, message }
                    setTimeout(() => this.toast.show = false, 2500)
                },
                openConfirm(action, payload, title, message, confirmText = 'Confirm') {
                    this.confirm = {
                        action,
                        payload,
                        title,
                        message,
                        confirmText,
                        busy: false,
                    }
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'confirm-action' }))
                },
                closeConfirm() {
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-action' }))
                },
                async confirmProceed() {
                    this.confirm.busy = true
                    try {
                        if (this.confirm.action === 'sendReminders') {
                            await this._sendReminders()
                        }
                        if (this.confirm.action === 'sendInvitations') {
                            await this._sendInvitations()
                        }
                        if (this.confirm.action === 'retryAllFailed') {
                            await this._retryAllFailed()
                        }
                        if (this.confirm.action === 'deleteEvent') {
                            await this._destroyEvent()
                        }
                        this.closeConfirm()
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Action failed.'
                    } finally {
                        this.confirm.busy = false
                    }
                },
                openImportWizard() {
                    this.importWizard = {
                        step: 1,
                        file: null,
                        filename: '',
                        headers: [],
                        sampleRows: [],
                        mapping: { name: 'name', email: 'email', phone: 'phone' },
                        uploading: false,
                        uploadProgress: 0,
                        queued: false,
                        error: null,
                    }
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'import-wizard' }))
                },
                closeImportWizard() {
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'import-wizard' }))
                },
                nextImportStep() {
                    if (this.importWizard.step < 3) this.importWizard.step++
                },
                prevImportStep() {
                    if (this.importWizard.step > 1) this.importWizard.step--
                },
                normalizeHeader(v) {
                    return String(v || '').trim()
                },
                guessMapping(headers) {
                    const lower = headers.map(h => h.toLowerCase())
                    const find = (candidates) => {
                        for (const c of candidates) {
                            const idx = lower.findIndex(h => h === c || h.replace(/\s+/g, '_') === c)
                            if (idx !== -1) return headers[idx]
                        }
                        return ''
                    }
                    return {
                        name: find(['name', 'full_name']) || headers[0] || '',
                        email: find(['email', 'email_address']),
                        phone: find(['phone', 'phone_number', 'mobile', 'msisdn']),
                    }
                },
                parseCsvLine(line) {
                    const out = []
                    let cur = ''
                    let inQuotes = false
                    for (let i = 0; i < line.length; i++) {
                        const ch = line[i]
                        if (ch === '"' && (i === 0 || line[i - 1] !== '\\')) {
                            if (inQuotes && line[i + 1] === '"') {
                                cur += '"'
                                i++
                            } else {
                                inQuotes = !inQuotes
                            }
                            continue
                        }
                        if (ch === ',' && !inQuotes) {
                            out.push(cur)
                            cur = ''
                            continue
                        }
                        cur += ch
                    }
                    out.push(cur)
                    return out.map(v => v.trim())
                },
                async onImportFileSelected(e) {
                    const file = e.target.files?.[0]
                    if (!file) return

                    this.importWizard.file = file
                    this.importWizard.filename = file.name
                    this.importWizard.error = null
                    this.importWizard.headers = []
                    this.importWizard.sampleRows = []

                    const ext = file.name.split('.').pop()?.toLowerCase() || ''

                    if (ext !== 'csv') {
                        this.importWizard.mapping = { name: 'name', email: 'email', phone: 'phone' }
                        return
                    }

                    const text = await file.text()
                    const lines = text.split(/\r?\n/).filter(l => l.trim() !== '').slice(0, 6)
                    if (!lines.length) return

                    const headers = this.parseCsvLine(lines[0]).map(this.normalizeHeader).filter(Boolean)
                    this.importWizard.headers = headers
                    this.importWizard.mapping = this.guessMapping(headers)

                    const rows = []
                    for (let i = 1; i < lines.length; i++) {
                        const cols = this.parseCsvLine(lines[i])
                        const row = {}
                        headers.forEach((h, idx) => row[h] = cols[idx] ?? '')
                        rows.push(row)
                    }
                    this.importWizard.sampleRows = rows
                },
                async uploadImport() {
                    if (!this.importWizard.file) return
                    if (!this.importWizard.mapping.name) {
                        this.importWizard.error = 'Name column is required.'
                        return
                    }

                    this.importWizard.uploading = true
                    this.importWizard.uploadProgress = 0
                    this.importWizard.error = null

                    const form = new FormData()
                    form.append('file', this.importWizard.file)
                    form.append('mapping[name]', this.importWizard.mapping.name)
                    if (this.importWizard.mapping.email) form.append('mapping[email]', this.importWizard.mapping.email)
                    if (this.importWizard.mapping.phone) form.append('mapping[phone]', this.importWizard.mapping.phone)

                    try {
                        await window.axios.post('/admin/events/' + this.eventId + '/guests/import', form, {
                            headers: { 'Content-Type': 'multipart/form-data' },
                            onUploadProgress: (p) => {
                                if (!p.total) return
                                this.importWizard.uploadProgress = Math.round((p.loaded / p.total) * 100)
                            },
                        })
                        this.importWizard.queued = true
                        this.notify('Import queued.')
                    } catch (e) {
                        this.importWizard.error = e?.response?.data?.message || 'Import failed.'
                    } finally {
                        this.importWizard.uploading = false
                        if (this.importWizard.uploadProgress === 0) this.importWizard.uploadProgress = 100
                        this.loadGuests(1)
                    }
                },
                async loadGuests(page) {
                    this.guests.loading = true
                    this.error = null
                    try {
                        const res = await window.axios.get('/admin/events/' + this.eventId + '/guests', { params: { page, search: this.guests.filters.search, status: this.guests.filters.status } })
                        this.guests.items = res.data.data || []
                        this.guests.pagination = {
                            current_page: res.data.current_page,
                            last_page: res.data.last_page,
                            next_page_url: res.data.next_page_url,
                            prev_page_url: res.data.prev_page_url,
                        }
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Failed to load guests.'
                    } finally {
                        this.guests.loading = false
                    }
                },
                async resend(guest) {
                    this.error = null
                    try {
                        await window.axios.post('/admin/events/' + this.eventId + '/guests/' + guest.id + '/resend')
                        this.notify('Resend queued.')
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Resend failed.'
                    }
                },
                async sendReminders() {
                    this.error = null
                    this.openConfirm('sendReminders', null, 'Send reminders', 'Send reminders to eligible guests now?', 'Send')
                },
                async _sendReminders() {
                    const res = await window.axios.post('/admin/events/' + this.eventId + '/guests/reminders')
                    this.notify('Reminders queued: ' + (res.data.queued ?? 0))
                },
                async loadPreview() {
                    this.error = null
                    try {
                        const res = await window.axios.get('/admin/events/' + this.eventId + '/invitations/preview')
                        this.invites.preview = res.data.channels
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Failed to load preview.'
                    }
                },
                async sendInvitations() {
                    this.error = null
                    this.openConfirm('sendInvitations', null, 'Send invitations', 'Send invitations to all unsent guests now?', 'Send')
                },
                async _sendInvitations() {
                    this.invites.sending = true
                    try {
                        const res = await window.axios.post('/admin/events/' + this.eventId + '/invitations/send')
                        this.notify('Invitations queued: ' + (res.data.queued ?? 0))
                    } finally {
                        this.invites.sending = false
                    }
                },
                async loadFailed(page) {
                    this.deliveries.loading = true
                    this.error = null
                    try {
                        const res = await window.axios.get('/admin/events/' + this.eventId + '/deliveries/failed', { params: { page } })
                        this.deliveries.failedItems = res.data.data || []
                        this.deliveries.pagination = {
                            current_page: res.data.current_page,
                            last_page: res.data.last_page,
                            next_page_url: res.data.next_page_url,
                            prev_page_url: res.data.prev_page_url,
                        }
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Failed to load failed deliveries.'
                    } finally {
                        this.deliveries.loading = false
                    }
                },
                async retryDelivery(delivery) {
                    this.error = null
                    try {
                        await window.axios.post('/admin/events/' + this.eventId + '/deliveries/' + delivery.id + '/retry')
                        this.notify('Retry queued.')
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Retry failed.'
                    }
                },
                async retryAllFailed() {
                    this.error = null
                    this.openConfirm('retryAllFailed', null, 'Retry failed deliveries', 'Retry all failed deliveries for this event?', 'Retry all')
                },
                async _retryAllFailed() {
                    this.deliveries.retryingAll = true
                    try {
                        const res = await window.axios.post('/admin/events/' + this.eventId + '/deliveries/retry-failed')
                        this.notify('Queued: ' + (res.data.queued ?? 0))
                        this.loadFailed(this.deliveries.pagination?.current_page || 1)
                    } finally {
                        this.deliveries.retryingAll = false
                    }
                },
                openEdit() {
                    this.editor.errors = {}
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'event-edit' }))
                },
                closeEdit() {
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'event-edit' }))
                },
                async saveEvent() {
                    this.editor.saving = true
                    this.editor.errors = {}
                    this.error = null

                    const payload = {
                        title: this.editor.form.title,
                        description: this.event.description || null,
                        event_start: this.editor.form.event_start ? new Date(this.editor.form.event_start).toISOString() : null,
                        timezone: this.editor.form.timezone,
                        venue: this.editor.form.venue,
                        capacity: this.editor.form.capacity,
                        settings: {
                            allow_plus_ones: this.editor.form.allow_plus_ones,
                            custom_questions: Array.isArray(this.event.settings?.custom_questions) ? this.event.settings.custom_questions : [],
                            enable_waitlist: this.editor.form.enable_waitlist,
                        },
                    }

                    try {
                        const res = await window.axios.put('/admin/events/' + this.eventId, payload)
                        this.event = res.data
                        this.notify('Event updated.')
                        this.closeEdit()
                    } catch (e) {
                        if (e?.response?.status === 422) {
                            this.editor.errors = e.response.data.errors || {}
                        } else {
                            this.error = e?.response?.data?.message || 'Update failed.'
                        }
                    } finally {
                        this.editor.saving = false
                    }
                },
                async duplicate() {
                    this.error = null
                    try {
                        const res = await window.axios.post('/admin/events/' + this.eventId + '/duplicate')
                        window.location.href = '/events/' + res.data.id
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Duplicate failed.'
                    }
                },
                async destroy() {
                    this.error = null
                    this.openConfirm('deleteEvent', null, 'Delete event', 'Delete this event? This cannot be undone.', 'Delete')
                },
                async _destroyEvent() {
                    await window.axios.delete('/admin/events/' + this.eventId)
                    window.location.href = '{{ route('dashboard', absolute: false) }}'
                },
            }
        }
    </script>
</x-app-layout>
