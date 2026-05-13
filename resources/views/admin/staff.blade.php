<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Staff') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div x-data="staffPage()" x-init="init()" class="space-y-6">
                <div x-show="toast.show" x-cloak class="rounded-md bg-green-50 p-3 text-sm text-green-700" x-text="toast.message"></div>
                <div x-show="error" x-cloak class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="error"></div>

                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 space-y-4">
                        <h3 class="text-lg font-medium text-gray-900">Invite staff</h3>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div class="flex-1">
                                <x-input-label for="email" value="Email" />
                                <x-text-input id="email" class="mt-1 block w-full" type="email" x-model="inviteEmail" placeholder="staff@example.com" />
                                <div class="mt-1 text-sm text-red-600" x-show="errors.email" x-text="errors.email?.[0]" x-cloak></div>
                            </div>
                            <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50" :disabled="inviting" x-on:click="invite()" x-text="inviting ? 'Sending...' : 'Send invite'"></button>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900">Staff members</h3>
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="u in staff.items" :key="u.id">
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-900" x-text="u.name"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600" x-text="u.email"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600" x-text="u.created_at"></td>
                                        </tr>
                                    </template>
                                    <tr x-show="!loading && staff.items.length === 0" x-cloak>
                                        <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-600">No staff members yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900">Invites</h3>
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expires</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Accepted</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="inv in invites.items" :key="inv.id">
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-900" x-text="inv.email"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600" x-text="inv.expires_at"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600" x-text="inv.accepted_at || '-'"></td>
                                        </tr>
                                    </template>
                                    <tr x-show="!loading && invites.items.length === 0" x-cloak>
                                        <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-600">No invites yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function staffPage() {
            return {
                loading: false,
                error: null,
                toast: { show: false, message: '' },
                inviting: false,
                inviteEmail: '',
                errors: {},
                staff: { items: [] },
                invites: { items: [] },
                init() {
                    this.load()
                },
                notify(message) {
                    this.toast = { show: true, message }
                    setTimeout(() => this.toast.show = false, 2500)
                },
                async load() {
                    this.loading = true
                    this.error = null
                    try {
                        const res = await window.axios.get('/admin/staff')
                        this.staff.items = res.data.staff.data || []
                        this.invites.items = res.data.invites.data || []
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Failed to load staff.'
                    } finally {
                        this.loading = false
                    }
                },
                async invite() {
                    this.inviting = true
                    this.error = null
                    this.errors = {}
                    try {
                        await window.axios.post('/admin/staff/invite', { email: this.inviteEmail })
                        this.inviteEmail = ''
                        this.notify('Invite sent.')
                        await this.load()
                    } catch (e) {
                        if (e?.response?.status === 422) {
                            this.errors = e.response.data.errors || {}
                        } else {
                            this.error = e?.response?.data?.message || 'Invite failed.'
                        }
                    } finally {
                        this.inviting = false
                    }
                },
            }
        }
    </script>
</x-app-layout>

