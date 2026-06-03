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
					placeholder="/"
					class="importer-url-input" />
				<button class="button" @click="pickDestination">
					{{ t('importer', 'Browse') }}
				</button>
				<select v-if="grantGroups.length > 0"
					v-model="storageLocation"
					:title="t('importer', 'Save to home folder or a grant folder')">
					<option value="home">{{ t('importer', 'Home') }}</option>
					<option v-for="g in grantGroups" :key="g.gid" :value="g.gid">
						{{ g.gid }}
					</option>
				</select>
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
				<button v-if="browser.entries.length > 0"
					class="button"
					:disabled="!canQueue || scanning"
					@click="queueAllVisible">
					{{ scanning ? t('importer', 'Scanning…') : t('importer', 'Queue all files') }}
				</button>
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
					@click="entry.is_dir ? browserNavigate(entry.url) : queueSingleFile(entry)">
					<span :class="entry.is_dir ? 'icon-folder' : 'icon-file'" />
					{{ entry.name }}
					<span v-if="!entry.is_dir && entry.size" class="importer-size">{{ formatSize(entry.size) }}</span>
				</li>
			</ul>
		</div>

		<!-- ── Job queue ── -->
		<div class="importer-queue section">
			<div class="importer-queue-header">
				<h3>{{ t('importer', 'Download queue') }}<span v-if="jobs.length" class="importer-queue-count"> ({{ queuedCount }}/{{ jobs.length }})</span></h3>
				<button v-if="jobs.some(j => j.status === 'queued')"
					class="button button-vue primary"
					@click="downloadAll">
					{{ t('importer', 'Download all') }}
				</button>
				<label v-if="jobs.some(j => j.status === 'queued')" class="importer-parallel-label">
					{{ t('importer', 'Parallel:') }}
					<input v-model.number="parallelLimit"
						type="number" min="1" max="20"
						class="importer-parallel-input"
						@change="saveParallelLimit" />
				</label>
				<label v-if="jobs.some(j => j.status === 'queued')"
					class="importer-overwrite-label"
					:title="t('importer', 'Overwrite any existing files with the same names')">
					<input type="checkbox" v-model="overwrite" />
					{{ t('importer', 'Overwrite') }}
				</label>
				<button v-if="jobs.some(j => j.status !== 'running')"
					class="button"
					@click="clearQueue">
					{{ t('importer', 'Clear queue') }}
				</button>
			</div>
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
						<td>{{ displayDestination(job.destination) }}</td>
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
import { listJobs, queueJob, deleteJob, listRemote, listGrantGroups, processJob } from './api.js'

export default {
	name: 'ImporterApp',

	data() {
		return {
			form: {
				provider: 'http',
				sourceUrl: '',
				destination: '/',
			},
			storageLocation: 'home',
			overwrite: false,
			grantGroups: [],
			formError: null,
			scanning: false,
			parallelLimit: parseInt(localStorage.getItem('importer_parallel') || '3', 10),
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
		queuedCount() {
			return this.jobs.filter(j => j.status === 'queued').length
		},
		effectiveDestination() {
			if (this.storageLocation === 'home') return this.form.destination
			const sub = this.form.destination.replace(/^\//, '')
			return `grant:${this.storageLocation}:${sub}`
		},
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
		this.loadGrantGroups()
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

		async loadGrantGroups() {
			this.grantGroups = await listGrantGroups()
		},

		async pollJobs() {
			const hasActive = this.jobs.some(j => j.status === 'queued' || j.status === 'running')
			if (hasActive) await this.loadJobs()
		},

		async queueImport() {
			this.formError = null
			if (!this.canQueue) return
			try {
				const job = await queueJob(this.form.provider, this.form.sourceUrl.trim(), this.effectiveDestination)
				this.jobs.unshift(job)
				this.form.sourceUrl = ''
			} catch (e) {
				this.formError = e?.response?.data?.ocs?.data?.error ?? t('importer', 'Failed to queue download')
			}
		},

		saveParallelLimit() {
			const v = Math.max(1, Math.min(20, this.parallelLimit || 1))
			this.parallelLimit = v
			localStorage.setItem('importer_parallel', String(v))
		},

		async downloadAll() {
			const queued = this.jobs.filter(j => j.status === 'queued').length
			if (queued === 0) return
			const workers = Math.min(queued, this.parallelLimit)
			// Stagger starts so the first worker creates destination folders
			// before others race on the same non-existent paths
			await Promise.all(Array.from({ length: workers }, (_, i) =>
				new Promise(r => setTimeout(r, i * 300)).then(() => this.runWorker())
			))
		},

		async runWorker() {
			while (true) {
				let result
				try {
					result = await processJob(this.overwrite)
				} catch {
					break
				}
				await this.loadJobs()
				if (result.done) break
			}
		},

		async clearQueue() {
			const removable = this.jobs.filter(j => j.status !== 'running')
			await Promise.all(removable.map(j => deleteJob(j.id).catch(() => {})))
			this.jobs = this.jobs.filter(j => j.status === 'running')
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

		async queueSingleFile(entry) {
			this.formError = null
			try {
				const job = await queueJob(this.form.provider, entry.url, this.effectiveDestination)
				this.jobs.unshift(job)
			} catch (e) {
				this.formError = e?.response?.data?.ocs?.data?.error ?? t('importer', 'Failed to queue download')
			}
		},

		async queueAllVisible() {
			this.formError = null
			this.scanning = true
			try {
				await this.queueRecursive(this.browser.url, this.browser.entries, '')
			} finally {
				this.scanning = false
			}
		},

		destWithSubdir(relDir) {
			if (!relDir) return this.effectiveDestination
			if (this.storageLocation === 'home') {
				return this.form.destination.replace(/\/$/, '') + '/' + relDir
			}
			const sub = this.form.destination.replace(/^\//, '').replace(/\/$/, '')
			return `grant:${this.storageLocation}:${sub ? sub + '/' + relDir : relDir}`
		},

		async queueRecursive(baseUrl, entries, relDir) {
			for (const entry of entries) {
				if (entry.is_dir) {
					let subEntries
					try {
						subEntries = await listRemote(this.form.provider, entry.url)
					} catch (e) {
						this.formError = e?.response?.data?.ocs?.data?.error ?? t('importer', 'Could not list directory')
						continue
					}
					const subDir = relDir ? relDir + '/' + entry.name : entry.name
					await this.queueRecursive(entry.url, subEntries, subDir)
				} else {
					try {
						const job = await queueJob(this.form.provider, entry.url, this.destWithSubdir(relDir))
						this.jobs.unshift(job)
					} catch (e) {
						this.formError = e?.response?.data?.ocs?.data?.error ?? t('importer', 'Failed to queue some files')
					}
				}
			}
		},

		suppressGrantCrumb() {
			const hide = () => {
				const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
				let node
				while ((node = walker.nextNode())) {
					if (node.textContent.trim() !== '.uga_grants') continue
					// Walk up to find the best breadcrumb item to hide
					let target = node.parentElement
					let el = target
					while (el && el !== document.body) {
						if (['LI', 'A', 'BUTTON'].includes(el.tagName)) {
							target = el
							if (el.parentElement && ['UL', 'OL', 'NAV'].includes(el.parentElement.tagName)) break
						}
						el = el.parentElement
					}
					if (target) target.style.display = 'none'
				}
			}
			hide()
			const observer = new MutationObserver(hide)
			observer.observe(document.body, { subtree: true, childList: true, characterData: true })
			return observer
		},

		pickDestination() {
			if (!window.OC?.dialogs?.filepicker) return
			const isGrant   = this.storageLocation !== 'home'
			const startPath = isGrant ? '/.uga_grants/' + this.storageLocation : undefined
			const observer  = isGrant ? this.suppressGrantCrumb() : null
			OC.dialogs.filepicker(
				t('importer', 'Choose destination folder'),
				(path) => {
					if (observer) observer.disconnect()
					if (path.startsWith('/.uga_grants/')) {
						const rest  = path.slice('/.uga_grants/'.length)
						const slash = rest.indexOf('/')
						const gid   = slash === -1 ? rest : rest.slice(0, slash)
						const sub   = slash === -1 ? '/' : rest.slice(slash) || '/'
						if (this.grantGroups.some(g => g.gid === gid)) {
							this.storageLocation  = gid
							this.form.destination = sub
							return
						}
					}
					this.storageLocation  = 'home'
					this.form.destination = path || '/'
				},
				false, 'httpd/unix-directory', true,
				OC.dialogs.FILEPICKER_TYPE_CHOOSE,
				startPath,
			)
		},

		statusLabel(status) {
			const map = {
				queued:  t('importer', 'Queued'),
				running: t('importer', 'Downloading'),
				done:    t('importer', 'Done'),
				skipped: t('importer', 'Skipped'),
				failed:  t('importer', 'Failed'),
			}
			return map[status] ?? status
		},

		displayDestination(dest) {
			const d = String(dest ?? '')
			if (d.startsWith('grant:')) {
				const [, gid, sub] = d.split(':', 3)
				return gid + ':/' + (sub || '')
			}
			return d
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
