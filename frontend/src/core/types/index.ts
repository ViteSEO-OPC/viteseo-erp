export type UserRole = 'Admin' | 'PM' | 'Member';

export interface User {
  id: number;
  name: string;
  email: string;
  avatar?: string | null;
  role?: UserRole;
  preferences?: Record<string, unknown> | null;
  created_at?: string;
}

export interface AuthResponse {
  user: User;
  token: string;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface RegisterCredentials {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

/** Laravel API Resource envelope: `{ data, meta?, links? }`. */
export interface ApiResource<T> {
  data: T;
  meta?: Record<string, unknown>;
  links?: Record<string, unknown>;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
}
