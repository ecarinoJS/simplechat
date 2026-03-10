/**
 * Negotiate with the Laravel backend to get Azure Web PubSub credentials.
 */

export interface NegotiateResponse {
  endpoint: string;
  hub: string;
  token: string;
  expires: number;
}

const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

/**
 * Fetch client credentials from the backend.
 * The user must be authenticated (via Sanctum session or token).
 */
export async function negotiate(): Promise<NegotiateResponse> {
  const response = await fetch(`${API_URL}/api/negotiate`, {
    method: 'GET',
    credentials: 'include', // Include cookies for Sanctum session auth
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  });

  if (!response.ok) {
    if (response.status === 401) {
      throw new Error('Authentication required. Please log in.');
    }
    throw new Error(`Negotiate failed: ${response.status} ${response.statusText}`);
  }

  return response.json();
}
