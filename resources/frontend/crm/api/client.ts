import type { CrmApiClient, CrmRequestOptions } from '../types/global';

function headersWithJson(options: CrmRequestOptions): Headers {
  const headers = new Headers(options.headers || undefined);

  if (!headers.has('Accept')) {
    headers.set('Accept', 'application/json');
  }

  if (options.body && !(options.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  return headers;
}

function requestBody(body: CrmRequestOptions['body']): BodyInit | null | undefined {
  if (
    !body ||
    typeof body !== 'object' ||
    body instanceof FormData ||
    body instanceof Blob ||
    body instanceof URLSearchParams
  ) {
    return body as BodyInit | null | undefined;
  }

  return JSON.stringify(body);
}

async function parseJson<T>(response: Response): Promise<T> {
  const payload = await response.json().catch(() => null);

  if (!response.ok || (payload && typeof payload === 'object' && 'ok' in payload && payload.ok === false)) {
    const message =
      payload && typeof payload === 'object' && 'error' in payload
        ? String(payload.error)
        : `Requete HUB refusee (${response.status})`;

    throw new Error(message);
  }

  return payload as T;
}

export async function crmRequest<T = unknown>(url: string, options: CrmRequestOptions = {}): Promise<T> {
  const response = await fetch(url, {
    ...options,
    body: requestBody(options.body),
    credentials: options.credentials || 'same-origin',
    headers: headersWithJson(options),
  });

  return parseJson<T>(response);
}

export const crmApi: CrmApiClient = {
  get: <T = unknown>(url: string, options: CrmRequestOptions = {}) => crmRequest<T>(url, { ...options, method: 'GET' }),
  post: <T = unknown>(url: string, body: CrmRequestOptions['body'] = {}, options: CrmRequestOptions = {}) =>
    crmRequest<T>(url, { ...options, body, method: 'POST' }),
  request: crmRequest,
};

export function installCrmApiClient(): void {
  window.MartinSolsCrmApi = crmApi;
}
