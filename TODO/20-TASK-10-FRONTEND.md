# Task 10: Vue Frontend (Optional)

**Status:** Ready after Task 9  
**Estimated Duration:** 8-12 hours (flexible scope)  
**Difficulty:** Medium

---

## Objective

Build web interfaces for admin, agent, and user panels.

**Note:** This is optional and depends on project scope. Focus on API first.

---

## Step 1: Frontend Setup

### Initialize Vue Project

```bash
npm create vite@latest frontend -- --template vue
cd frontend
npm install
```

### Install Dependencies

- **Vue Router** — Page routing
- **Pinia** — State management
- **Axios** — HTTP client
- **Tailwind CSS** — Styling
- **Vee-Validate** — Form validation

---

## Step 2: Project Structure

```
frontend/
├── src/
│   ├── layouts/
│   │   ├── AdminLayout.vue
│   │   ├── AgentLayout.vue
│   │   └── UserLayout.vue
│   ├── pages/
│   │   ├── Auth/
│   │   │   ├── Login.vue
│   │   │   ├── Register.vue
│   │   │   └── OtpVerification.vue
│   │   ├── Admin/
│   │   │   ├── Dashboard.vue
│   │   │   ├── Users.vue
│   │   │   ├── Agents.vue
│   │   │   └── Settings.vue
│   │   ├── Agent/
│   │   │   ├── Dashboard.vue
│   │   │   └── Transactions.vue
│   │   └── User/
│   │       ├── Dashboard.vue
│   │       ├── Wallet.vue
│   │       ├── Transfer.vue
│   │       └── History.vue
│   ├── components/
│   │   ├── Forms/
│   │   ├── Cards/
│   │   └── Modals/
│   ├── stores/
│   │   ├── auth.ts
│   │   ├── user.ts
│   │   └── wallet.ts
│   ├── services/
│   │   └── api.ts
│   ├── App.vue
│   └── main.ts
├── index.html
├── vite.config.ts
└── tailwind.config.ts
```

---

## Step 3: Authentication in Frontend

### Auth Service

```typescript
// src/services/api.ts
const api = axios.create({
  baseURL: 'http://localhost:8000/api/v1'
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      // Token expired, refresh or logout
    }
    return Promise.reject(error);
  }
);

export default api;
```

### Auth Store (Pinia)

```typescript
// src/stores/auth.ts
import { defineStore } from 'pinia';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token'),
  }),
  
  actions: {
    async login(phone, pin) {
      const res = await api.post('/auth/login', { phone_number: phone, pin });
      this.token = res.data.data.token;
      this.user = res.data.data.user;
      localStorage.setItem('token', this.token);
      return res.data;
    },
    
    logout() {
      this.token = null;
      this.user = null;
      localStorage.removeItem('token');
    },
  },
});
```

---

## Step 4: Key Pages

### Login Page
- Form: phone/email + password/pin
- OTP verification for admin
- Error handling
- Loading states

### Dashboard
- Show user/wallet info
- Recent transactions
- Quick actions

### Wallet Page
- Display balance
- Send/receive/withdraw buttons
- Transaction history
- Block/unblock (admin)

### User Management (Admin)
- User list with pagination
- Register user modal
- View user details
- Approve/suspend agents

### Transaction Forms
- Transfer form
- Cash-in/out form
- Validation
- Confirmation before submit

---

## Step 5: State Management

**Pinia Stores:**
- `auth.ts` — Authentication state
- `user.ts` — User profile
- `wallet.ts` — Wallet data
- `transactions.ts` — Transaction history

---

## Step 6: Error Handling & UX

### Toast Notifications
- Success messages
- Error messages
- Loading indicators

### Form Validation
- Client-side validation
- Server error display
- Field-level errors

### Loading States
- Skeleton loaders
- Spinners
- Disabled buttons during submit

---

## Files to Create

- All Vue components
- Stores
- Services
- Layouts
- Styles

---

## Checklist

- [ ] Authentication flow complete
- [ ] All layouts created
- [ ] Core pages functional
- [ ] Forms validate input
- [ ] API calls working
- [ ] Error messages displayed
- [ ] Loading states working
- [ ] Mobile responsive (Tailwind)

---

## Notes

- Start with core functionality
- Don't spend too much time on styling initially
- Focus on correct API integration
- Test thoroughly with real backend
