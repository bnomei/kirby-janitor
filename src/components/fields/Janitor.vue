<template>
	<k-field
		:label="button.headline || headline"
		:help="button.help || help"
		class="janitor-wrapper"
	>
		<k-button
			:id="id"
			:class="['k-button janitor', button.state]"
			:icon="currentIcon"
			:text="button.label || responseLabel || text || label"
			:title="title"
			:badge="badge"
			:dialog="dialog"
			:drawer="drawer"
			:link="link"
			:target="target"
			:disabled="isDisabled"
			:dropdown="listItem && !command && !isDisabled"
			:style="buttonStyle"
			:theme="button.theme || theme"
			variant="filled"
			@click="runJanitor"
		/>
		<a
			v-show="downloadRequest"
			ref="downloadAnchor"
			class="visually-hidden"
			:href="downloadRequest"
			download
		/>
		<a
			v-show="urlRequest"
			ref="tabAnchor"
			class="visually-hidden"
			:href="urlRequest"
			target="_blank"
		/>
	</k-field>
</template>

<script>
import { useId } from "vue";

export default {
	props: {
		autosave: Boolean,
		backgroundColor: String,
		badge: Object,
		clipboard: Boolean,
		color: String,
		confirm: String,
		command: String,
		cooldown: Number,
		dialog: String,
		disabled: Boolean,
		drawer: String,
		error: String,
		icon: String,
		intab: Boolean,
		help: String,
		label: String,
		link: String,
		listItem: Boolean,
		headline: String,
		progress: String,
		success: String,
		target: String,
		text: String,
		theme: String,
		title: String,
		unsaved: { type: Boolean, default: true }
	},
	setup() {
		return { id: `janitor-${useId()}` };
	},

	data() {
		return {
			button: {
				label: null,
				state: null,
				headline: null,
				help: null,
				theme: null,
				style: null
			},
			clipboardRequest: null,
			downloadRequest: null,
			icons: {
				"is-running": "janitorLoader",
				"is-success": "check",
				"has-error": "alert"
			},
			responseIcon: null,
			responseLabel: null,
			resetTimer: null,
			urlRequest: null
		};
	},

	computed: {
		isDisabled() {
			return (
				this.disabled ||
				[this.command, this.link, this.dialog, this.drawer].filter(
					Boolean
				).length !== 1 ||
				this.button.state === "is-running" ||
				(!this.unsaved && this.hasChanges)
			);
		},
		buttonStyle() {
			return (
				this.button.style || {
					color: this.color,
					backgroundColor: this.backgroundColor
				}
			);
		},
		currentIcon() {
			return (
				this.icons[this.button.state] ?? this.responseIcon ?? this.icon
			);
		},
		hasChanges() {
			return this.$panel.content.hasDiff();
		}
	},

	unmounted() {
		clearTimeout(this.resetTimer);
	},

	methods: {
		async runJanitor() {
			if (!this.command || this.isDisabled) {
				return;
			}

			if (this.confirm && !window.confirm(this.confirm)) {
				return;
			}

			clearTimeout(this.resetTimer);
			if (this.clipboardRequest !== null) {
				await this.copyToClipboard(this.clipboardRequest);
				if (this.clipboardRequest === null) this.resetButton();
				return;
			}

			try {
				if (this.autosave && this.hasChanges) {
					await this.$panel.content.publish();
					if (this.hasChanges) return;
				}
				await this.postRequest("plugin-janitor", {
					command: this.command,
					view: this.$panel.view.props.api
				});
			} catch (error) {
				this.resetButton();
				this.$panel.notification.error(error.message);
			}
		},

		async postRequest(path, data) {
			this.button.label =
				this.progress ||
				`${this.responseLabel || this.text || this.label} …`;
			this.button.state = "is-running";

			const {
				label,
				message,
				status,
				reload,
				open,
				download,
				clipboard,
				success,
				icon,
				headline,
				help,
				color,
				backgroundColor,
				resetStyle,
				notification,
				error,
				warn,
				log
			} = await this.$api.post(path, data);

			if (status === 200) {
				this.button.label = success ?? this.success;
			} else {
				this.button.label = error ?? this.error;
			}

			if (label) {
				this.responseLabel = label;
			}

			if (message) {
				this.button.label = message;
			}

			if (help) {
				this.button.help = help;
			}

			if (headline) {
				this.button.headline = headline;
			}

			if (icon) {
				this.responseIcon = icon;
			}

			this.button.style = { reset: true };
			if (status) {
				this.button.state = status === 200 ? "is-success" : "has-error";
				this.button.theme = status === 200 ? "positive" : "negative";
			} else {
				this.button.theme = null;
				this.button.state = "has-response";
			}

			if (color) {
				this.button.style.reset = false;
				this.button.style.color = color;
			}

			if (backgroundColor) {
				this.button.style.reset = false;
				this.button.style.backgroundColor = backgroundColor;
			}

			if (resetStyle) {
				this.button.style.reset = resetStyle;
			}

			if (notification) {
				let [f, m] = notification;
				window.panel.notification[f](m);
			}

			if (error) {
				console.error(error);
			}

			if (warn) {
				console.warn(warn);
			}

			if (log) {
				console.log(log);
			}

			if (reload) {
				setTimeout(() => location.reload(), this.cooldown || 0);
			}

			if (open) {
				if (this.intab) {
					this.urlRequest = open;
					this.$nextTick(() => {
						this.simulateClick(this.$refs.tabAnchor);
					});
				} else {
					location.href = open;
				}
			}

			if (download) {
				this.downloadRequest = download;
				this.$nextTick(() => {
					this.simulateClick(this.$refs.downloadAnchor);
				});
			}

			if (typeof clipboard === "string") {
				this.button.label = this.progress;
				await this.copyToClipboard(clipboard);
			}

			this.resetTimer = setTimeout(this.resetButton, this.cooldown ?? 2000);
		},

		resetButton() {
			this.button.label = null;
			this.button.state = null;
			this.button.theme = null;
			if (this.button.style?.reset) {
				this.button.style = null;
			}
		},

		simulateClick(element) {
			const evt = new MouseEvent("click", {
				bubbles: true,
				cancelable: true,
				view: window
			});

			element.dispatchEvent(evt);
		},

		copyToClipboardWithTextarea(content) {
			const textArea = document.createElement("textarea");
			textArea.value = content;
			textArea.style.position = "absolute";
			textArea.style.left = "-999999px";
			document.body.prepend(textArea);
			textArea.select();
			try {
				return document.execCommand("copy");
			} catch (error) {
				console.error(error);
				return false;
			} finally {
				textArea.remove();
			}
		},

		async copyToClipboard(content) {
			this.button.state = "is-running";
			let copied;
			try {
				await navigator.clipboard.writeText(content);
				copied = true;
			} catch {
				copied = this.copyToClipboardWithTextarea(content);
			}
			// Only retry this value on the next click if both copy methods failed.
			this.clipboardRequest = copied ? null : content;
			this.button.state = copied ? "is-success" : "has-error";
			this.button.theme = copied ? "positive" : "negative";
		}
	}
};
</script>

<style>
.janitor.is-running {
	cursor: wait;
}

.janitor[aria-disabled="true"] {
	cursor: not-allowed;
}

.visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	border: 0;
	padding: 0;
	margin: 0;
	clip-path: inset(50%);
	overflow: hidden;
	white-space: nowrap;
}
</style>
