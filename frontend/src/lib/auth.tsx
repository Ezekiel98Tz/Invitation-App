import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { api, ensureCsrf } from './api';
import type { User } from './types';

interface AuthContextValue {
    user: User | null;
    loading: boolean;
    login: (email: string, password: string) => Promise<void>;
    register: (name: string, email: string, password: string, passwordConfirmation: string) => Promise<void>;
    logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = useCallback(async () => {
        try {
            await ensureCsrf();
            const { data } = await api.get<{ user: User }>('/api/v1/me');
            setUser(data.user);
        } catch {
            setUser(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    const login = useCallback(async (email: string, password: string) => {
        await ensureCsrf();
        const { data } = await api.post<{ user: User }>('/api/v1/auth/login', { email, password });
        setUser(data.user);
    }, []);

    const register = useCallback(
        async (name: string, email: string, password: string, passwordConfirmation: string) => {
            await ensureCsrf();
            const { data } = await api.post<{ user: User }>('/api/v1/auth/register', {
                name,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });
            setUser(data.user);
        },
        [],
    );

    const logout = useCallback(async () => {
        try {
            await api.post('/api/v1/auth/logout');
        } finally {
            setUser(null);
        }
    }, []);

    const value = useMemo<AuthContextValue>(
        () => ({ user, loading, login, register, logout }),
        [user, loading, login, register, logout],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const ctx = useContext(AuthContext);
    if (!ctx) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return ctx;
}
