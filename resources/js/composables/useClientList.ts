import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';

type MaybeRef<T> = T | Ref<T> | ComputedRef<T>;

function unwrap<T>(value: MaybeRef<T>): T {
    return typeof value === 'object' && value !== null && 'value' in value ? value.value : value;
}

export function useClientList<T>(
    items: MaybeRef<T[]>,
    options: {
        perPage?: number;
        searchKeys?: (item: T) => string[];
        filterFn?: (item: T) => boolean;
    } = {},
) {
    const search = ref('');
    const page = ref(1);
    const perPage = options.perPage ?? 10;

    const filtered = computed(() => {
        let list = [...(unwrap(items) ?? [])];

        if (options.filterFn) {
            list = list.filter(options.filterFn);
        }

        const q = search.value.trim().toLowerCase();
        if (q && options.searchKeys) {
            list = list.filter((item) =>
                options.searchKeys!(item).some((value) => value.toLowerCase().includes(q)),
            );
        }

        return list;
    });

    const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / perPage)));

    const paginated = computed(() => {
        const start = (page.value - 1) * perPage;
        return filtered.value.slice(start, start + perPage);
    });

    watch([search, filtered], () => {
        if (page.value > totalPages.value) {
            page.value = totalPages.value;
        }
    });

    watch(search, () => {
        page.value = 1;
    });

    return {
        search,
        page,
        filtered,
        paginated,
        totalPages,
        perPage,
        total: computed(() => filtered.value.length),
    };
}
