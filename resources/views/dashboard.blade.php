<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Events') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div
                x-data="eventsDashboard()"
                x-init="init()"
                class="space-y-6"
            >
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-600">
                        <span x-show="stats" x-cloak>
                            <span class="font-medium text-gray-900" x-text="stats.total_events"></span> events
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700"
                            x-on:click="openCreate()"
                        >
                            Create event
                        </button>
                    </div>
                </div>

                <div x-show="toast.show" x-cloak class="rounded-md bg-green-50 p-3 text-sm text-green-700" x-text="toast.message"></div>
                <div x-show="error" x-cloak class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="error"></div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900">Your events</h3>
                            <div class="text-sm text-gray-500" x-show="loading" x-cloak>Loading...</div>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Start</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Venue</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Response</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="event in events" :key="event.id">
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-900" x-text="event.title"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600" x-text="event.event_start"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600" x-text="event.venue"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600">
                                                <span x-show="statsById[event.id]" x-cloak>
                                                    <span class="font-medium" x-text="statsById[event.id].response_rate + '%'"></span>
                                                    <span class="text-gray-500" x-text="'(' + statsById[event.id].guests_responded + '/' + statsById[event.id].guests_total + ')'"></span>
                                                </span>
                                                <span x-show="!statsById[event.id]" x-cloak>-</span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-right">
                                                <div class="inline-flex items-center gap-2">
                                                    <a
                                                        class="text-indigo-600 hover:text-indigo-900"
                                                        :href="eventManageUrl(event.id)"
                                                    >Manage</a>
                                                    <button type="button" class="text-gray-600 hover:text-gray-900" x-on:click="openEdit(event)">Edit</button>
                                                    <button type="button" class="text-gray-600 hover:text-gray-900" x-on:click="duplicate(event)">Duplicate</button>
                                                    <button type="button" class="text-red-600 hover:text-red-700" x-on:click="destroy(event)">Delete</button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="!loading && events.length === 0" x-cloak>
                                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-600">No events yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 flex items-center justify-between text-sm text-gray-600" x-show="pagination" x-cloak>
                            <div>
                                Page <span class="font-medium" x-text="pagination.current_page"></span> of <span class="font-medium" x-text="pagination.last_page"></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                    :disabled="!pagination.prev_page_url"
                                    x-on:click="loadEvents(pagination.current_page - 1)"
                                >Prev</button>
                                <button
                                    type="button"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                    :disabled="!pagination.next_page_url"
                                    x-on:click="loadEvents(pagination.current_page + 1)"
                                >Next</button>
                            </div>
                        </div>
                    </div>
                </div>

                <x-modal name="event-editor" :show="false" maxWidth="2xl" focusable>
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900" x-text="editor.mode === 'create' ? 'Create event' : 'Edit event'"></h2>

                        <div class="mt-4 grid grid-cols-1 gap-4">
                            <div>
                                <x-input-label for="title" value="Title" />
                                <x-text-input id="title" class="mt-1 block w-full" type="text" x-model="editor.form.title" />
                                <div class="mt-1 text-sm text-red-600" x-show="formErrors.title" x-text="formErrors.title?.[0]" x-cloak></div>
                            </div>

                            <div>
                                <x-input-label for="venue" value="Venue" />
                                <x-text-input id="venue" class="mt-1 block w-full" type="text" x-model="editor.form.venue" />
                                <div class="mt-1 text-sm text-red-600" x-show="formErrors.venue" x-text="formErrors.venue?.[0]" x-cloak></div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="event_start" value="Event start" />
                                    <x-text-input id="event_start" class="mt-1 block w-full" type="datetime-local" x-model="editor.form.event_start" />
                                    <div class="mt-1 text-sm text-red-600" x-show="formErrors.event_start" x-text="formErrors.event_start?.[0]" x-cloak></div>
                                </div>

                                <div>
                                    <x-input-label for="timezone" value="Timezone" />
                                    <x-text-input id="timezone" class="mt-1 block w-full" type="text" x-model="editor.form.timezone" />
                                    <div class="mt-1 text-sm text-red-600" x-show="formErrors.timezone" x-text="formErrors.timezone?.[0]" x-cloak></div>
                                </div>
                            </div>

                            <div>
                                <x-input-label for="capacity" value="Capacity (optional)" />
                                <x-text-input id="capacity" class="mt-1 block w-full" type="number" min="1" x-model.number="editor.form.capacity" />
                                <div class="mt-1 text-sm text-red-600" x-show="formErrors.capacity" x-text="formErrors.capacity?.[0]" x-cloak></div>
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
                            <button
                                type="button"
                                class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                                x-on:click="closeEditor()"
                                :disabled="editor.saving"
                            >Cancel</button>
                            <button
                                type="button"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                                x-on:click="save()"
                                :disabled="editor.saving"
                                x-text="editor.saving ? 'Saving...' : 'Save'"
                            ></button>
                        </div>
                    </div>
                </x-modal>

                <x-modal name="confirm-dashboard" :show="false" maxWidth="lg" focusable>
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
        function eventsDashboard() {
            return {
                events: [],
                pagination: null,
                stats: null,
                statsById: {},
                loading: false,
                error: null,
                toast: { show: false, message: '' },
                formErrors: {},
                confirm: {
                    action: null,
                    payload: null,
                    title: '',
                    message: '',
                    confirmText: 'Confirm',
                    busy: false,
                },
                editor: {
                    mode: 'create',
                    saving: false,
                    eventId: null,
                    form: {
                        title: '',
                        description: null,
                        event_start: '',
                        timezone: 'Africa/Dar_es_Salaam',
                        venue: '',
                        capacity: null,
                        allow_plus_ones: false,
                        enable_waitlist: false,
                    },
                },
                init() {
                    this.loadEvents(1)
                    this.loadStats()
                    setInterval(() => this.loadStats(), 15000)
                },
                eventManageUrl(id) {
                    return '/events/' + id
                },
                openCreate() {
                    this.formErrors = {}
                    this.editor.mode = 'create'
                    this.editor.eventId = null
                    this.editor.saving = false
                    this.editor.form = {
                        title: '',
                        description: null,
                        event_start: '',
                        timezone: 'Africa/Dar_es_Salaam',
                        venue: '',
                        capacity: null,
                        allow_plus_ones: false,
                        enable_waitlist: false,
                    }
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'event-editor' }))
                },
                openEdit(event) {
                    this.formErrors = {}
                    this.editor.mode = 'edit'
                    this.editor.eventId = event.id
                    this.editor.saving = false
                    this.editor.form = {
                        title: event.title,
                        description: event.description || null,
                        event_start: this.toDatetimeLocal(event.event_start),
                        timezone: event.timezone || 'Africa/Dar_es_Salaam',
                        venue: event.venue,
                        capacity: event.capacity || null,
                        allow_plus_ones: !!(event.settings && event.settings.allow_plus_ones),
                        enable_waitlist: !!(event.settings && event.settings.enable_waitlist),
                    }
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'event-editor' }))
                },
                closeEditor() {
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'event-editor' }))
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
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'confirm-dashboard' }))
                },
                closeConfirm() {
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-dashboard' }))
                },
                async confirmProceed() {
                    this.confirm.busy = true
                    try {
                        if (this.confirm.action === 'deleteEvent') {
                            await this._destroy(this.confirm.payload)
                        }
                        this.closeConfirm()
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Action failed.'
                    } finally {
                        this.confirm.busy = false
                    }
                },
                toDatetimeLocal(value) {
                    if (!value) return ''
                    const d = new Date(value.replace(' ', 'T'))
                    if (isNaN(d.getTime())) return ''
                    const pad = n => String(n).padStart(2, '0')
                    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
                },
                async loadEvents(page) {
                    this.loading = true
                    this.error = null
                    try {
                        const res = await window.axios.get('/admin/events', { params: { page } })
                        this.events = res.data.data || []
                        this.pagination = {
                            current_page: res.data.current_page,
                            last_page: res.data.last_page,
                            next_page_url: res.data.next_page_url,
                            prev_page_url: res.data.prev_page_url,
                        }
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Failed to load events.'
                    } finally {
                        this.loading = false
                    }
                },
                async loadStats() {
                    try {
                        const res = await window.axios.get('/admin/reports/events')
                        this.stats = res.data
                        this.statsById = {}
                        for (const e of (res.data.events || [])) {
                            this.statsById[e.id] = e
                        }
                    } catch (e) {
                    }
                },
                async save() {
                    this.editor.saving = true
                    this.formErrors = {}
                    this.error = null

                    const payload = {
                        title: this.editor.form.title,
                        description: this.editor.form.description,
                        event_start: this.editor.form.event_start ? new Date(this.editor.form.event_start).toISOString() : null,
                        timezone: this.editor.form.timezone,
                        venue: this.editor.form.venue,
                        capacity: this.editor.form.capacity,
                        settings: {
                            allow_plus_ones: this.editor.form.allow_plus_ones,
                            custom_questions: [],
                            enable_waitlist: this.editor.form.enable_waitlist,
                        },
                    }

                    try {
                        if (this.editor.mode === 'create') {
                            await window.axios.post('/admin/events', payload)
                            this.toast = { show: true, message: 'Event created.' }
                        } else {
                            await window.axios.put('/admin/events/' + this.editor.eventId, payload)
                            this.toast = { show: true, message: 'Event updated.' }
                        }
                        this.closeEditor()
                        await this.loadEvents(this.pagination?.current_page || 1)
                        this.loadStats()
                        setTimeout(() => this.toast.show = false, 2500)
                    } catch (e) {
                        if (e?.response?.status === 422) {
                            this.formErrors = e.response.data.errors || {}
                        } else {
                            this.error = e?.response?.data?.message || 'Save failed.'
                        }
                    } finally {
                        this.editor.saving = false
                    }
                },
                async duplicate(event) {
                    this.error = null
                    try {
                        await window.axios.post('/admin/events/' + event.id + '/duplicate')
                        this.toast = { show: true, message: 'Event duplicated.' }
                        await this.loadEvents(this.pagination?.current_page || 1)
                        this.loadStats()
                        setTimeout(() => this.toast.show = false, 2500)
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Duplicate failed.'
                    }
                },
                async destroy(event) {
                    this.error = null
                    this.openConfirm('deleteEvent', event, 'Delete event', 'Delete this event? This cannot be undone.', 'Delete')
                },
                async _destroy(event) {
                    await window.axios.delete('/admin/events/' + event.id)
                    this.toast = { show: true, message: 'Event deleted.' }
                    await this.loadEvents(this.pagination?.current_page || 1)
                    this.loadStats()
                    setTimeout(() => this.toast.show = false, 2500)
                },
            }
        }
    </script>
</x-app-layout>
