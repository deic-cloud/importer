import axios from '@nextcloud/axios'

const BASE = '/ocs/v2.php/apps/importer/api/v1'
const OCS  = { headers: { 'OCS-APIREQUEST': 'true' } }

export async function listJobs() {
	const { data } = await axios.get(`${BASE}/jobs`, OCS)
	return data.ocs?.data ?? []
}

export async function queueJob(provider, sourceUrl, destination, overwrite = false) {
	const { data } = await axios.post(`${BASE}/jobs`, { provider, sourceUrl, destination, overwrite }, OCS)
	return data.ocs?.data ?? {}
}

export async function deleteJob(id) {
	await axios.delete(`${BASE}/jobs/${id}`, OCS)
}

export async function listCredentials() {
	const { data } = await axios.get(`${BASE}/credentials`, OCS)
	return data.ocs?.data ?? []
}

export async function saveCredentials(provider, host, creds) {
	await axios.post(`${BASE}/credentials`, { provider, host, creds }, OCS)
}

export async function deleteCredentials(provider, host) {
	await axios.delete(`${BASE}/credentials/${encodeURIComponent(provider)}/${encodeURIComponent(host)}`, OCS)
}

export async function listRemote(provider, url) {
	const { data } = await axios.post(`${BASE}/ls`, { provider, url }, OCS)
	return data.ocs?.data ?? []
}

export async function retryFailed() {
	const { data } = await axios.post(`${BASE}/retry`, {}, OCS)
	return data.ocs?.data ?? {}
}

export async function retryJob(id) {
	await axios.post(`${BASE}/retry/${id}`, {}, OCS)
}

export async function prepareDestinations() {
	await axios.post(`${BASE}/prepare`, {}, OCS)
}

export async function processJob(overwrite = false) {
	const { data } = await axios.post(`${BASE}/process`, { overwrite }, OCS)
	return data.ocs?.data ?? { done: true }
}

export async function listGrantGroups() {
	try {
		const { data } = await axios.get(`${BASE}/grant-groups`, OCS)
		return data.ocs?.data ?? []
	} catch {
		return []
	}
}
