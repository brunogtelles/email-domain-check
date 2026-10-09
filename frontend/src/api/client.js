import axios from 'axios'

export const api = axios.create({
  baseURL: process.env.API_URL,
  headers: { Accept: 'application/json' },
  timeout: 60000,
})

/**
 * Turn an Axios error into a message that can be shown to the user.
 */
export function errorMessage(error) {
  const response = error?.response

  if (!response) {
    return error?.code === 'ECONNABORTED' ? 'The request timed out.' : 'Cannot reach the API. Is the Laravel server running?'
  }

  if (response.status === 429) {
    return 'Too many requests. Please wait a moment and try again.'
  }

  const errors = response.data?.errors
  if (errors) {
    return Object.values(errors).flat()[0]
  }

  return response.data?.message || `Request failed (${response.status}).`
}
