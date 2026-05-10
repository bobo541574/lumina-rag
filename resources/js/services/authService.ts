import { post, get } from './api'
import type { User } from '../types'

export interface AuthResult {
  user: User
  token: string
}

export const authService = {
  async login(email: string, password: string) {
    return post<AuthResult>('/auth/login', { email, password })
  },

  async register(name: string, email: string, password: string) {
    return post<AuthResult>('/auth/register', { name, email, password, password_confirmation: password })
  },

  async me() {
    return get<User>('/auth/me')
  },

  async logout() {
    return post('/auth/logout')
  },
}
