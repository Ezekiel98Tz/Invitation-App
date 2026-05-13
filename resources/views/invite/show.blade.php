@php
    $settings = (array) ($guest->event->settings ?? []);
    $allowPlusOnes = (bool) ($settings['allow_plus_ones'] ?? false);
    $customQuestions = is_array($settings['custom_questions'] ?? null) ? $settings['custom_questions'] : [];
@endphp

<x-guest-layout>
    <div
        x-data="inviteRsvp({
            submitUrl: '{{ route('invites.store', ['token' => $guest->invite_token], absolute: false) }}',
            inviteUrl: '{{ route('invites.show', ['token' => $guest->invite_token], absolute: false) }}',
            allowPlusOnes: {{ $allowPlusOnes ? 'true' : 'false' }},
            customQuestions: @js($customQuestions),
            initial: {
                status: 'accepted',
                attending_count: 1,
                answers: {},
            },
        })"
        class="space-y-6"
    >
        <div class="text-center">
            <h1 class="text-xl font-semibold text-gray-900">{{ $guest->event->title }}</h1>
            <p class="mt-1 text-sm text-gray-600">Hello {{ $guest->name }}</p>
        </div>

        <div x-show="confirmed" x-cloak class="space-y-4 text-center">
            <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">
                <span x-show="confirmedData.status === 'accepted'">Thanks! You’re marked as attending.</span>
                <span x-show="confirmedData.status === 'declined'">Thanks! You’re marked as not attending.</span>
            </div>

            <template x-if="allowPlusOnes && confirmedData.status === 'accepted'">
                <div class="text-sm text-gray-700">
                    Attending: <span class="font-medium" x-text="confirmedData.attending_count"></span>
                </div>
            </template>

            <div class="flex items-center justify-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                    x-on:click="editResponse"
                >
                    Edit response
                </button>
                <a
                    class="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700"
                    :href="inviteUrl"
                >
                    Refresh
                </a>
            </div>
        </div>

        <div class="space-y-2 text-sm text-gray-700">
            @if($guest->event->venue)
                <div><span class="font-medium">Venue:</span> {{ $guest->event->venue }}</div>
            @endif
            @if($guest->event->event_start)
                <div><span class="font-medium">Starts:</span> {{ $guest->event->event_start->timezone($guest->event->timezone)->toDayDateTimeString() }} ({{ $guest->event->timezone }})</div>
            @endif
        </div>

        <form x-show="!confirmed" x-cloak class="space-y-4" method="POST" action="{{ route('invites.store', ['token' => $guest->invite_token], absolute: false) }}" x-on:submit.prevent="submit">
            @csrf

            <div class="space-y-2">
                <div class="text-sm font-medium text-gray-700">Will you attend?</div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="status" value="accepted" class="text-indigo-600 focus:ring-indigo-500" x-model="form.status">
                    <span>Yes</span>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="status" value="declined" class="text-indigo-600 focus:ring-indigo-500" x-model="form.status">
                    <span>No</span>
                </label>
                <template x-if="errors.status">
                    <div class="text-sm text-red-600" x-text="errors.status[0]"></div>
                </template>
            </div>

            <template x-if="allowPlusOnes && form.status === 'accepted'">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="attending_count">Number attending</label>
                    <input
                        id="attending_count"
                        type="number"
                        min="1"
                        max="5"
                        name="attending_count"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        x-model.number="form.attending_count"
                    >
                    <template x-if="errors.attending_count">
                        <div class="mt-1 text-sm text-red-600" x-text="errors.attending_count[0]"></div>
                    </template>
                </div>
            </template>

            <template x-if="form.status === 'accepted' && customQuestions.length">
                <div class="space-y-4">
                    <template x-for="q in customQuestions" :key="q.key">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" :for="'q_'+q.key" x-text="q.key"></label>

                            <template x-if="q.type === 'choice'">
                                <select
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :id="'q_'+q.key"
                                    :name="'answers['+q.key+']'"
                                    x-model="form.answers[q.key]"
                                >
                                    <option value="" x-show="!q.required"></option>
                                    <template x-for="opt in (Array.isArray(q.options) ? q.options : [])" :key="opt">
                                        <option :value="opt" x-text="opt"></option>
                                    </template>
                                </select>
                            </template>

                            <template x-if="q.type === 'boolean'">
                                <div class="mt-2">
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" x-model="form.answers[q.key]">
                                        <span>Yes</span>
                                    </label>
                                </div>
                            </template>

                            <template x-if="q.type === 'number'">
                                <input
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :id="'q_'+q.key"
                                    type="number"
                                    :name="'answers['+q.key+']'"
                                    x-model="form.answers[q.key]"
                                >
                            </template>

                            <template x-if="!q.type || q.type === 'text'">
                                <input
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :id="'q_'+q.key"
                                    type="text"
                                    :name="'answers['+q.key+']'"
                                    x-model="form.answers[q.key]"
                                >
                            </template>

                            <template x-if="errors['answers.'+q.key]">
                                <div class="mt-1 text-sm text-red-600" x-text="errors['answers.'+q.key][0]"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            <div class="space-y-2">
                <button
                    type="submit"
                    class="w-full inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                    :disabled="loading"
                    x-text="loading ? 'Submitting...' : 'Submit RSVP'"
                ></button>

                <template x-if="serverError">
                    <div class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="serverError"></div>
                </template>
            </div>
        </form>
    </div>

    <script>
        function inviteRsvp(config) {
            return {
                submitUrl: config.submitUrl,
                inviteUrl: config.inviteUrl,
                allowPlusOnes: config.allowPlusOnes,
                customQuestions: config.customQuestions || [],
                form: {
                    status: config.initial.status,
                    attending_count: config.initial.attending_count,
                    answers: config.initial.answers || {},
                },
                loading: false,
                confirmed: false,
                serverError: null,
                errors: {},
                confirmedData: {
                    status: config.initial.status,
                    attending_count: config.initial.attending_count,
                    answers: config.initial.answers || {},
                },
                editResponse() {
                    this.confirmed = false
                },
                async submit() {
                    this.loading = true
                    this.confirmed = false
                    this.serverError = null
                    this.errors = {}

                    const payload = {
                        status: this.form.status,
                    }

                    if (this.allowPlusOnes && this.form.status === 'accepted') {
                        payload.attending_count = this.form.attending_count
                    }

                    if (this.form.status === 'accepted') {
                        payload.answers = this.form.answers
                    }

                    try {
                        const res = await window.axios.post(this.submitUrl, payload)
                        const rsvp = res?.data?.rsvp || {}
                        this.confirmedData = {
                            status: payload.status,
                            attending_count: rsvp.attending_count ?? (payload.attending_count ?? 0),
                            answers: rsvp.answers ?? (payload.answers ?? {}),
                        }
                        this.confirmed = true
                    } catch (e) {
                        const status = e?.response?.status

                        if (status === 410) {
                            window.location.reload()
                            return
                        }

                        if (status === 422) {
                            this.errors = e.response.data.errors || {}
                        } else {
                            this.serverError = e?.response?.data?.message || 'Something went wrong.'
                        }
                    } finally {
                        this.loading = false
                    }
                },
            }
        }
    </script>
</x-guest-layout>
