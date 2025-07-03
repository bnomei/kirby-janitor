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
			:command="command"
			:disabled="!unsaved && !isUnsaved && hasChanges"
			:style="buttonStyle"
			variant="filled"
			@click="runJanitor"
		>
			{{ button.label || label }}
		</k-button>
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
const STORAGE_ID = "janitor.runAfterAutosave";

export default {
	props: {
		autosave: Boolean,
		backgroundColor: String,
		clipboard: Boolean,
		color: String,
		confirm: String,
		command: String,
		cooldown: Number,
		error: String,
		icon: String,
		intab: Boolean,
		help: String,
		label: String,
		headline: String,
		progress: String,
		success: String,
		status: String,
		unsaved: Boolean
	},

	data() {
		return {
			button: {
				label: null,
				state: null,
				headline: null,
				help: null,
				style: null
			},
			clipboardRequest: null,
			downloadRequest: null,
			icons: {
				"is-running": "janitorLoader",
				"is-success": "check",
				"has-error": "alert"
			},
			isUnsaved: false,
			urlRequest: null
		};
	},

	computed: {
		buttonStyle() {
			return (
				this.button.style || {
					color: this.color,
					backgroundColor: this.backgroundColor
				}
			);
		},
		currentIcon() {
			return this.icons[this.status] ?? this.icon;
		},
		id() {
			return (
				"janitor-" +
				this.hashCode(
					this.command + (this.button.label ?? "") + this.label
				)
			);
		},
		hasChanges() {
			return Object.keys(this.$panel.content.diff()).length > 0;
		}
	},

	created() {
		this.eventHandler = () =>
			sessionStorage.getItem(STORAGE_ID) && location.reload();
		window.panel.events.on("model.update", this.eventHandler);

		if (sessionStorage.getItem(STORAGE_ID) === this.id) {
			sessionStorage.removeItem(STORAGE_ID);
			this.runJanitor();
		}
	},

	unmounted() {
		window.panel.events.off("model.update", this.eventHandler);
	},

	methods: {
		/**
		 * Source: https://stackoverflow.com/a/8831937
		 */
		hashCode(str) {
			let hash = 0;

			if (str.length === 0) {
				return hash;
			}

			for (const i of str) {
				hash = (hash << 5) - hash + str.charCodeAt(i);
				// convert to 32bit integer
				hash = hash & hash;
			}

			return hash;
		},

		async runJanitor() {
			if (this.confirm && !window.confirm(this.confirm)) {
				return;
			}

			if (this.autosave && this.hasChanges) {
				// lock janitor button, press save and listen to `model.update` event
				const saveButton = document.querySelector(
					".k-panel .k-header-buttons .k-form-controls button:nth-child(2)"
				);

				// revert & save
				if (saveButton) {
					this.isUnsaved = false;
					sessionStorage.setItem(STORAGE_ID, this.id);
					this.simulateClick(saveButton);
					return;
				}
			}

			if (this.clipboardRequest) {
				await this.copyToClipboard(this.clipboardRequest);
				this.resetButton();
				this.clipboardRequest = null;
				return;
			}

			if (this.status) {
				return;
			}

			await this.postRequest("plugin-janitor", { command: this.command });
		},

		async postRequest(path, data) {
			this.button.label = this.progress ?? `${this.label} …`;
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
				this.label = label;
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
				this.icon = icon;
			}

			this.button.style = {
				color: "white",
				reset: true
			};
			if (status) {
				this.button.state = status === 200 ? "is-success" : "has-error";
				this.button.style.backgroundColor =
					status === 200
						? "var(--color-positive)"
						: "var(--color-negative-light)";
			} else {
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
				location.reload();
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

			if (clipboard) {
				this.clipboardRequest = clipboard;
				this.button.label = this.progress;
				this.button.state = "is-success";

				setTimeout(this.resetButton, this.cooldown);

				this.$nextTick(() => {
					this.copyToClipboard(this.clipboardRequest);
				});
			} else {
				console.log(this.cooldown);
				setTimeout(this.resetButton, this.cooldown || 2000);
			}
		},

		resetButton() {
			this.button.label = null;
			this.button.state = null;
			this.button.style = this.button.style?.reset
				? null
				: this.button.style;
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
				if (document.execCommand("copy") === false) {
					console.error(
						"Using `navigator.clipboard` did not work most likely due to the Kirby Panel running on localhost or no https. Janitor then tried to copy with a textarea but that failed as well."
					);
				}
			} catch (error) {
				console.error(error);
			} finally {
				textArea.remove();
			}
		},

		async copyToClipboard(content) {
			try {
				await navigator.clipboard.writeText(content);
			} catch (error) {
				console.error(error);
				this.copyToClipboardWithTextarea(content);
			}
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
