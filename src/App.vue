<template>
	<div class="importer-app">
		<h2>{{ t('importer', 'Import files') }}</h2>

		<!-- ── Import form ── -->
		<div class="importer-form section">
			<div class="importer-row">
				<label>{{ t('importer', 'Provider') }}</label>
				<select v-model="form.provider" @change="onProviderChange">
					<option value="http">HTTP / HTTPS</option>
					<option value="ftp">FTP</option>
					<option value="s3">S3 / Object Store</option>
					<option value="webdav">WebDAV</option>
				</select>
			</div>

			<div class="importer-row">
				<label>{{ t('importer', 'Source URL') }}</label>
				<input v-model="form.sourceUrl"
					type="text"
					:placeholder="urlPlaceholder"
					class="importer-url-input"
					@keyup.enter="queueImport" />
				<button v-if="canBrowse" class="button" @click="browseRemote">
					{{ t('importer', 'Browse') }}
				</button>
			</div>

			<div class="importer-row">
				<label>{{ t('importer', 'Destination folder') }}</label>
				<input v-model="form.destination"
					type="text"
					placeholder="/Import"
					class="importer-url-input" />
				<button class="button" @click="pickDestination">
					{{ t('importer', 'Browse') }}
				</button>
			</div>

			<div class="importer-row">
				<button class="button button-vue primary" :disabled="!canQueue" @click="queueImport">
					{{ t('importer', 'Queue download') }}
				</button>
			</div>

			<p v-if="formError" class="importer-error">{{ formError }}</p>
		</div>

		<!-- ── Remote browser ── -->
		<div v-if="browser.visible" class="importer-browser section">
			<div class="importer-browser-bar">
				<span class="importer-browser-url" :title="browser.url">{{ browser.url }}</span>
				<button class="button icon-close" @click="browser.visible = false" />
			</div>
			<p v-if="browser.error" class="importer-error">{{ browser.error }}</p>
			<div v-if="browser.loading" class="icon-loading" />
			<ul v-else class="importer-browser-list">
				<li v-if="browser.canGoUp" @click="browserGoUp">
					<span class="icon-folder" /> ..
				</li>
				<li v-for="entry in browser.entries"
					:key="entry.url"
					:class="{ 'is-dir': entry.is_dir }"
					@click="entry.is_dir ? browserNavigate(entry.url) : selectFile(entry)">
					<span :class="entry.is_dir ? 'icon-folder' : 'icon-file'" />
					{{ entry.name }}
					<span v-if="!entry.is_dir && entry.size" class="importer-size">{{ formatSize(entry.size) }}</span>
				</li>
			</ul>
		</div>

		<!-- ── Job queue ── -->
		<div class="importer-queue section">
			<h3>{{ t('importer', 'Download queue') }}</h3>
			<p v-if="jobs.length === 0" class="importer-empty">
				{{ t('importer', 'No downloads queued.') }}
			</p>
			<table v-else class="importer-jobs">
				<thead>
					<tr>
						<th>{{ t('importer', 'Source') }}</th>
						<th>{{ t('importer', 'Destination') }}</th>
						<th>{{ t('importer', 'Status') }}</th>
						<th>{{ t('importer', 'Progress') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="job in jobs" :key="job.id" :class="`status-${job.status}`">
						<td :title="job.source_url" class="importer-cell-url">{{ basename(job.source_url) }}</td>
						<td>{{ job.destination }}</td>
						<td>{{ statusLabel(job.status) }}</td>
						<td>
							<div v-if="job.status === 'running'" class="importer-progress">
								<div class="importer-progress-bar" :style="{ width: job.progress + '%' }" />
							</div>
							<span v-else-if="job.status === 'failed'" class="importer-error-inline" :title="job.error_message">
								{{ t('importer', 'Failed') }}
							</span>
						</td>
						<td>
							<button v-if="job.status !== 'running'"
								class="button icon-delete"
								:title="t('importer', 'Remove')"
								@click="removeJob(job.id)" />
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { listJobs, queueJob, deleteJob, listRemote } from './api.js'

export default {
	name: 'ImporterApp',

	data() {
		return {
			form: {
				provider: 'http',
				sourceUrl: '',
				destination: '/Import',
			},
			formError: null,
			jobs: [],
			pollTimer: null,
			browser: {
				visible: false,
				loading: false,
				url: '',
				entries: [],
				history: [],
				error: null,
				canGoUp: false,
			},
		}
	},

	computed: {
		canQueue() {
			return this.form.sourceUrl.trim() !== '' && this.form.destination.trim() !== ''
		},
		canBrowse() {
			return ['ftp', 'webdav', 's3'].includes(this.form.provider)
		},
		urlPlaceholder() {
			const map = {
				http:   'https://example.com/data/file.tar.gz',
				ftp:    'ftp://ftp.example.com/pub/dataset/',
				s3:     's3://my-bucket/data/ or https://s3.region.amazonaws.com/bucket/prefix/',
				webdav: 'https://webdav.example.com/remote.php/dav/files/user/',
			}
			return map[this.form.provider] ?? ''
		},
	},

	mounted() {
		this.loadJobs()
		this.pollTimer = setInterval(this.pollJobs, 3000)
	},

	beforeDestroy() {
		clearInterval(this.pollTimer)
	},

	methods: {
		async loadJobs() {
			try {
				this.jobs = await listJobs()
			} catch (e) {
				console.error('[importer] loadJobs:', e)
			}
		},

		async pollJobs() {
			const hasActive = this.jobs.some(j => j.status === 'queued' || j.status === 'running')
			if (hasActive) await this.loadJobs()
		},

		async queueImport() {
			this.formError = null
			if (!this.canQueue) return
			try {
				const job = await queueJob(this.form.provider, this.form.sourceUrl.trim(), this.form.destination.trim())
				this.jobs.unshift(job)
				this.form.sourceUrl = ''
			} catch (e) {
				this.formError = e?.response?.data?.ocs?.data?.error ?? t('importer', 'Failed to queue download')
			}
		},

		async removeJob(id) {
			try {
				await deleteJob(id)
				this.jobs = this.jobs.filter(j => j.id !== id)
			} catch (e) {
				console.error('[importer] removeJob:', e)
			}
		},

		onProviderChange() {
			this.form.sourceUrl = ''
			this.browser.visible = false
		},

		async browseRemote() {
			if (!this.form.sourceUrl.trim()) return
			this.browser.visible  = true
			this.browser.history  = []
			this.browser.canGoUp  = false
			await this.loadBrowser(this.form.sourceUrl.trim())
		},

		async loadBrowser(url) {
			this.browser.loading = true
			this.browser.error   = null
			this.browser.url     = url
			try {
				this.browser.entries = await listRemote(this.form.provider, url)
				this.browser.canGoUp = this.browser.history.length > 0
			} catch (e) {
				this.browser.error = e?.response?.data?.ocs?.data?.error ?? t('importer', 'Could not list directory')
			} finally {
				this.browser.loading = false
			}
		},

		browserNavigate(url) {
			this.browser.history.push(this.browser.url)
			this.loadBrowser(url)
		},

		browserGoUp() {
			const prev = this.browser.history.pop()
			if (prev) this.loadBrowser(prev)
		},

		selectFile(entry) {
			this.form.sourceUrl  = entry.url
			this.browser.visible = false
		},

		pickDestination() {
			if (!window.OC?.dialogs?.filepicker) return
			OC.dialogs.filepicker(
				t('importer', 'Choose destination folder'),
				(path) => { this.form.destination = path },
				false, 'httpd/unix-directory', true,
				OC.dialogs.FILEPICKER_TYPE_CHOOSE,
			)
		},

		statusLabel(status) {
			const map = {
				queued:  t('importer', 'Queued'),
				running: t('importer', 'Downloading'),
				done:    t('importer', 'Done'),
				failed:  t('importer', 'Failed'),
			}
			return map[status] ?? status
		},

		basename(url) {
			try {
				return decodeURIComponent(url.split('/').filter(Boolean).pop() ?? url)
			} catch {
				return url
			}
		},

		formatSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 ** 2) return (bytes / 1024).toFixed(1) + ' KB'
			if (bytes < 1024 ** 3) return (bytes / 1024 ** 2).toFixed(1) + ' MB'
			return (bytes / 1024 ** 3).toFixed(2) + ' GB'
		},
	},
}
</script>
