import axios from 'axios'

// TODO rewrite this.
// Add new API on the server

// Create an Axios instance
const api = axios.create({
    baseURL: 'https://kiosk.hands-on-technology.org',
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('jwt_token');
    if (token) {
        config.headers['Authorization'] = `Bearer ${token}`;
    }
    return config;
}, (error) => {
    return Promise.reject(error);
});

export default api;