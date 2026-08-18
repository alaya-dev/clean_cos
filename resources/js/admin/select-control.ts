import { type Component, type PropType } from 'vue';

export type SelectOption = {
    value: string;
    label: string;
    disabled?: boolean;
};

const SelectControl: Component = {
    props: {
        modelValue: { type: String, required: true },
        options: { type: Array as PropType<SelectOption[]>, required: true },
        placeholder: { type: String, default: 'Choisir une option' },
        required: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue', 'change'],
    setup(_, { emit }) {
        const update = (event: Event) => {
            const value = (event.target as HTMLSelectElement).value;
            emit('update:modelValue', value);
            emit('change', value);
        };
        return { update };
    },
    template: '<select class="select-control-native" :value="modelValue" :required="required" :disabled="disabled" @change="update"><option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option></select>',
};

export default SelectControl;
