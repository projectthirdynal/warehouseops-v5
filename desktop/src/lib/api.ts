import axios, { AxiosInstance } from 'axios'

const STORAGE_KEY_TOKEN = 'warehouseops_token'
const STORAGE_KEY_SERVER = 'warehouseops_server'

class ApiClient {
  private client: AxiosInstance
  private serverUrl: string = ''

  constructor() {
    this.serverUrl = localStorage.getItem(STORAGE_KEY_SERVER) || ''
    this.client = axios.create({
      timeout: 30000,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    })

    // Attach token to every request
    this.client.interceptors.request.use((config) => {
      const token = this.getToken()
      if (token) {
        config.headers.Authorization = `Bearer ${token}`
      }
      config.baseURL = this.serverUrl
      return config
    })

    // Handle 401 (expired token)
    this.client.interceptors.response.use(
      (response) => response,
      (error) => {
        if (error.response?.status === 401) {
          this.clearToken()
          window.location.hash = '#/login'
        }
        return Promise.reject(error)
      }
    )
  }

  setServerUrl(url: string) {
    this.serverUrl = url.replace(/\/+$/, '')
    localStorage.setItem(STORAGE_KEY_SERVER, this.serverUrl)
  }

  getServerUrl(): string {
    return this.serverUrl
  }

  setToken(token: string) {
    localStorage.setItem(STORAGE_KEY_TOKEN, token)
  }

  getToken(): string | null {
    return localStorage.getItem(STORAGE_KEY_TOKEN)
  }

  clearToken() {
    localStorage.removeItem(STORAGE_KEY_TOKEN)
  }

  isAuthenticated(): boolean {
    return !!this.getToken() && !!this.serverUrl
  }

  // Auth
  async login(email: string, password: string) {
    const res = await this.client.post('/api/desktop/login', { email, password })
    return res.data
  }

  async logout() {
    try {
      await this.client.post('/api/desktop/logout')
    } finally {
      this.clearToken()
    }
  }

  async getUser() {
    const res = await this.client.get('/api/desktop/user')
    return res.data
  }

  // Dashboard
  async getDashboard() {
    const res = await this.client.get('/api/desktop/dashboard')
    return res.data
  }

  // Scanner
  async validateWaybill(waybillNumber: string) {
    const res = await this.client.post('/api/desktop/scanner/validate', { waybill_number: waybillNumber })
    return res.data
  }

  async dispatchBatch(waybillNumbers: string[]) {
    const res = await this.client.post('/api/desktop/scanner/dispatch', { waybill_numbers: waybillNumbers })
    return res.data
  }

  // Waybill Import
  async getUploads() {
    const res = await this.client.get('/api/desktop/imports')
    return res.data
  }

  async uploadWaybills(file: File, courierProvider: string) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('courier_provider', courierProvider)
    const res = await this.client.post('/api/desktop/imports', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    })
    return res.data
  }

  async getUploadDetail(id: number) {
    const res = await this.client.get(`/api/desktop/imports/${id}`)
    return res.data
  }

  async retryUpload(id: number) {
    const res = await this.client.post(`/api/desktop/imports/${id}/retry`)
    return res.data
  }

  // Monitoring
  async getMonitoringMetrics(dateRange?: string) {
    const res = await this.client.get('/api/desktop/monitoring', {
      params: { date_range: dateRange },
    })
    return res.data
  }

  // SMS
  async getSmsData() {
    const res = await this.client.get('/api/desktop/sms')
    return res.data
  }

  async smsPreviewRecipients(targetAudience: string) {
    const res = await this.client.post('/api/desktop/sms/preview', { target_audience: targetAudience })
    return res.data
  }

  async smsSendCampaign(data: { name: string; message: string; target_audience: string }) {
    const res = await this.client.post('/api/desktop/sms/campaigns', data)
    return res.data
  }

  async smsQuickSend(phone: string, message: string) {
    const res = await this.client.post('/api/desktop/sms/quick-send', { phone, message })
    return res.data
  }

  async smsCreateTemplate(data: { name: string; message: string; category: string }) {
    const res = await this.client.post('/api/desktop/sms/templates', data)
    return res.data
  }

  async smsDeleteTemplate(id: number) {
    const res = await this.client.delete(`/api/desktop/sms/templates/${id}`)
    return res.data
  }

  // Settings
  async updateProfile(data: { name: string }) {
    const res = await this.client.patch('/api/desktop/settings/profile', data)
    return res.data
  }

  async updatePassword(data: { current_password: string; password: string; password_confirmation: string }) {
    const res = await this.client.patch('/api/desktop/settings/password', data)
    return res.data
  }

  async updateAppearance(data: { theme: string }) {
    const res = await this.client.patch('/api/desktop/settings/appearance', data)
    return res.data
  }

  // Users
  async getUsers() {
    const res = await this.client.get('/api/desktop/users')
    return res.data
  }

  async createUser(data: { name: string; email: string; role: string; password: string }) {
    const res = await this.client.post('/api/desktop/users', data)
    return res.data
  }

  async updateUser(id: number, data: { name: string; email: string; role: string; password?: string }) {
    const res = await this.client.patch(`/api/desktop/users/${id}`, data)
    return res.data
  }

  async toggleUserActive(id: number) {
    const res = await this.client.patch(`/api/desktop/users/${id}/toggle-active`)
    return res.data
  }
}

export const api = new ApiClient()
