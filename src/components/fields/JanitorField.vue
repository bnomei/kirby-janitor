<template>
	<janitor
		v-if="!buttons"
		v-bind="$attrs"
		:label="label"
		:help="help"
		:disabled="disabled"
	/>
	<k-field
		v-else
		:label="label"
		:help="help"
		:input="false"
		class="k-buttons-field"
	>
		<k-button-group v-if="buttons.length">
			<janitor
				v-for="(button, index) in buttons"
				:key="`${index}:${
					button.command ||
					button.link ||
					button.dialog ||
					button.drawer
				}`"
				v-bind="button"
				:disabled="disabled || button.disabled"
				list-item
			/>
		</k-button-group>
		<k-empty v-else icon="bars">{{ $t("buttons.empty") }}</k-empty>
	</k-field>
</template>

<script>
import Janitor from "./Janitor.vue";

export default {
	components: { Janitor },
	inheritAttrs: false,
	props: {
		buttons: Array,
		disabled: Boolean,
		help: String,
		label: String
	}
};
</script>

<style>
.k-buttons-field .janitor-wrapper {
	width: 100%;
}
</style>
