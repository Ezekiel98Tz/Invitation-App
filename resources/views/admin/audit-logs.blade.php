<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Audit Logs') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div x-data="auditLogsPage()" x-init="load(1)" class="space-y-6">
                <div x-show="error" x-cloak class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="error"></div>

                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900">Recent activity</h3>
                            <div class="text-sm text-gray-500" x-show="loading" x-cloak>Loading...</div>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">When</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Meta</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="l in items" :key="l.id">
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-600" x-text="l.created_at"></td>
                                            <td class="px-4 py-3 text-sm text-gray-900" x-text="l.action"></td>
                                            <td class="px-4 py-3 text-sm text-gray-600">
                                                <span x-text="l.meta ? JSON.stringify(l.meta) : '-'"></span>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="!loading && items.length === 0" x-cloak>
                                        <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-600">No logs yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 flex items-center justify-between text-sm text-gray-600" x-show="pagination" x-cloak>
                            <div>
                                Page <span class="font-medium" x-text="pagination.current_page"></span> of <span class="font-medium" x-text="pagination.last_page"></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50" :disabled="!pagination.prev_page_url" x-on:click="load(pagination.current_page - 1)">Prev</button>
                                <button type="button" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50" :disabled="!pagination.next_page_url" x-on:click="load(pagination.current_page + 1)">Next</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function auditLogsPage() {
            return {
                items: [],
                pagination: null,
                loading: false,
                error: null,
                async load(page) {
                    this.loading = true
                    this.error = null
                    try {
                        const res = await window.axios.get('/admin/audit-logs', { params: { page } })
                        this.items = res.data.data || []
                        this.pagination = {
                            current_page: res.data.current_page,
                            last_page: res.data.last_page,
                            next_page_url: res.data.next_page_url,
                            prev_page_url: res.data.prev_page_url,
                        }
                    } catch (e) {
                        this.error = e?.response?.data?.message || 'Failed to load audit logs.'
                    } finally {
                        this.loading = false
                    }
                },
            }
        }
    </script>
</x-app-layout>

