<template>
	<div class="importer-personal section">
		<h2>{{ t('importer', 'Data import') }}</h2>
		<p class="settings-hint">
			{{ t('importer', 'Saved credentials for remote services. Passwords are stored encrypted.') }}
		</p>

		<!-- Existing credentials -->
		<table v-if="credentials.length > 0" class="importer-cred-table">
			<thead>
				<tr>
					<th>{{ t('importer', 'Provider') }}</th>
					<th>{{ t('importer', 'Host / Endpoint') }}</th>
					<th>{{ t('importer', 'Username / Key') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="c in credentials" :key="c.provider + '/' + c.host">
					<td>{{ providerLabel(c.provider) }}</td>
					<td>{{ c.host }}</td>
					<td>{{ c.creds.username }}<span v-if="c.provider === 'sftp' && c.creds.auth && c.creds.auth !== 'password'" class="importer-cred-auth"> · {{ authLabel(c.creds.auth) }}</span></td>
					<td>
						<button class="button icon-delete"
							:title="t('importer', 'Delete')"
							@click="removeCred(c.provider, c.host)" />
					</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="importer-empty">{{ t('importer', 'No saved credentials.') }}</p>

		<!-- Add credentials form -->
		<details class="importer-add-cred">
			<summary>{{ t('importer', 'Add credentials') }}</summary>
			<div class="importer-add-cred-form">
				<div class="importer-row">
					<label>{{ t('importer', 'Provider') }}</label>
					<select v-model="newCred.provider" @change="resetNewCred">
						<option value="http">HTTP / HTTPS</option>
						<option value="ftp">FTP</option>
						<option value="s3">S3 / Object Store</option>
						<option value="webdav">WebDAV</option>
						<option value="sftp">SFTP</option>
					</select>
				</div>

				<div class="importer-row">
					<label>{{ t('importer', 'Host / Endpoint') }}</label>
					<input v-model="newCred.host" type="text" :placeholder="hostPlaceholder" autocomplete="off" />
				</div>

				<!-- SFTP: password, the user's certificate key, or a private key -->
				<template v-if="newCred.provider === 'sftp'">
					<div class="importer-row">
						<label>{{ t('importer', 'Username') }}</label>
						<input v-model="newCred.username" type="text" autocomplete="off" />
					</div>
					<div class="importer-row">
						<label>{{ t('importer', 'Log in with') }}</label>
						<select v-model="newCred.auth">
							<option value="password">{{ t('importer', 'Password') }}</option>
							<option v-if="certificate.available" value="certificate">{{ t('importer', 'My certificate key') }}</option>
							<option value="key">{{ t('importer', 'A private key') }}</option>
						</select>
					</div>
					<div v-if="newCred.auth === 'password'" class="importer-row">
						<label>{{ t('importer', 'Password') }}</label>
						<input v-model="newCred.password" type="password" autocomplete="new-password" />
					</div>
					<p v-if="newCred.auth === 'certificate'" class="importer-hint">
						{{ t('importer', 'The key of your personal certificate (Settings → Access & certificates) is used when connecting; nothing is stored here. Put its public key in ~/.ssh/authorized_keys on the server:') }}
						<a :href="certificate.public_key_url" download="id_rsa.pub">{{ t('importer', 'Download the public key') }}</a>
					</p>
					<template v-if="newCred.auth === 'key'">
						<div class="importer-row">
							<label>{{ t('importer', 'Private key') }}</label>
							<textarea v-model="newCred.private_key" rows="5" spellcheck="false" autocomplete="off"
								placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" />
						</div>
						<div class="importer-row">
							<label>{{ t('importer', 'Passphrase (if any)') }}</label>
							<input v-model="newCred.passphrase" type="password" autocomplete="new-password" />
						</div>
						<p class="importer-hint">{{ t('importer', 'RSA keys only. The key is stored encrypted, like a password.') }}</p>
					</template>
				</template>

				<!-- Basic auth fields (http, ftp, webdav) -->
				<template v-else-if="newCred.provider !== 's3'">
					<div class="importer-row">
						<label>{{ t('importer', 'Username') }}</label>
						<input v-model="newCred.username" type="text" autocomplete="off" />
					</div>
					<div class="importer-row">
						<label>{{ t('importer', 'Password') }}</label>
						<input v-model="newCred.password" type="password" autocomplete="new-password" />
					</div>
				</template>

				<!-- S3 key fields -->
				<template v-else>
					<div class="importer-row">
						<label>{{ t('importer', 'Access Key') }}</label>
						<input v-model="newCred.access_key" type="text" autocomplete="off" />
					</div>
					<div class="importer-row">
						<label>{{ t('importer', 'Secret Key') }}</label>
						<input v-model="newCred.secret_key" type="password" autocomplete="new-password" />
					</div>
					<div class="importer-row">
						<label>{{ t('importer', 'Region') }}</label>
						<input v-model="newCred.region" type="text" placeholder="us-east-1" />
					</div>
					<div class="importer-row">
						<label>{{ t('importer', 'Endpoint (leave blank for AWS)') }}</label>
						<input v-model="newCred.endpoint" type="text" placeholder="https://minio.example.com" />
					</div>
				</template>

				<p v-if="saveError" class="importer-error">{{ saveError }}</p>

				<div class="importer-row">
					<button class="button button-vue primary" @click="addCred">
						{{ t('importer', 'Save') }}
					</button>
				</div>
			</div>
		</details>
	</div>
</template>

<script>
import { listCredentials, saveCredentials, deleteCredentials } from './api.js'

export default {
	name: 'ImporterPersonalSettings',

	data() {
		return {
			credentials: [],
			saveError: null,
			newCred: this.emptyNewCred('http'),
			certificate: (() => { try { return window.OCP.InitialState.loadState('importer', 'certificate_key') } catch (e) { return { available: false, public_key_url: '' } } })(),
		}
	},

	computed: {
		hostPlaceholder() {
			const map = {
				http:   'https://example.com',
				ftp:    'ftp.example.com',
				s3:     'https://s3.amazonaws.com (or leave blank for AWS)',
				webdav: 'https://webdav.example.com',
				sftp:   'sftp://sftp.example.com:22',
			}
			return map[this.newCred.provider] ?? ''
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		async load() {
			try {
				this.credentials = await listCredentials()
			} catch (e) {
				console.error('[importer] listCredentials:', e)
			}
		},

		async addCred() {
			this.saveError = null
			const c = this.newCred
			let host = c.host.trim()
			if (!host) {
				if (c.provider === 's3') {
					host = c.endpoint.trim() || 's3://'
				} else {
					this.saveError = t('importer', 'Host / Endpoint is required')
					return
				}
			}
			const creds = c.provider === 's3'
				? { access_key: c.access_key, secret_key: c.secret_key, region: c.region || 'us-east-1', endpoint: c.endpoint }
				: c.provider === 'sftp'
					? { username: c.username, auth: c.auth, password: c.password, private_key: c.private_key, passphrase: c.passphrase }
					: { username: c.username, password: c.password }
			try {
				await saveCredentials(c.provider, host, creds)
				this.newCred = this.emptyNewCred(c.provider)
				await this.load()
			} catch (e) {
				this.saveError = e?.response?.data?.ocs?.data?.error ?? t('importer', 'Could not save credentials')
			}
		},

		async removeCred(provider, host) {
			try {
				await deleteCredentials(provider, host)
				this.credentials = this.credentials.filter(c => !(c.provider === provider && c.host === host))
			} catch (e) {
				console.error('[importer] deleteCred:', e)
			}
		},

		resetNewCred() {
			this.newCred = this.emptyNewCred(this.newCred.provider)
		},

		emptyNewCred(provider) {
			return { provider, host: '', username: '', password: '', access_key: '', secret_key: '', region: '', endpoint: '', auth: 'password', private_key: '', passphrase: '' }
		},

		authLabel(a) {
			return a === 'certificate' ? t('importer', 'certificate key') : a === 'key' ? t('importer', 'private key') : ''
		},

		providerLabel(id) {
			const map = { http: 'HTTP/HTTPS', ftp: 'FTP', s3: 'S3', webdav: 'WebDAV', sftp: 'SFTP' }
			return map[id] ?? id
		},
	},
}
</script>
