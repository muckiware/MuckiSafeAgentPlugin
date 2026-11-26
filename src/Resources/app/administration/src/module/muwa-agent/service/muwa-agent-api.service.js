const ApiService = Shopware.Classes.ApiService;

/**
 * Admin action routes of the MuckiSafe Agent plugin. Reads stay on the generic
 * DAL repositories; every write goes through these server-side ACL-protected
 * routes. Responses carrying secrets are returned to the caller only and are
 * never cached or stored by this service.
 */
export default class MuwaAgentApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/muwa/agent') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'muwaAgentApiService';
    }

    provision(name) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/provision`, { name }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    rotate(agentId) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/${agentId}/rotate`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    revoke(agentId) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/${agentId}/revoke`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    saveAction(payload) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/action`, payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    saveHook(payload) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/repository-hook`, payload, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    deleteHook(hookId) {
        return this.httpClient
            .delete(`${this.getApiBasePath()}/repository-hook/${hookId}`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    jobOutput(jobId) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/job/${jobId}/output`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    rollbackStatus() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/rollback`, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    startRollback() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/rollback/start`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    stopRollback() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/rollback/stop`, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    validateAction(definition) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/action/validate`, { definition }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}
