import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { apiErrorMessage, validationErrors } from '../lib/api';
import { Alert, Field } from '../components/ui';

export function RegisterPage() {
    const { user, loading, register } = useAuth();
    const navigate = useNavigate();
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        if (!loading && user) {
            navigate('/dashboard', { replace: true });
        }
    }, [user, loading, navigate]);

    const update = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
        setForm((prev) => ({ ...prev, [key]: e.target.value }));

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        setErrors({});
        try {
            await register(form.name, form.email, form.password, form.password_confirmation);
            navigate('/dashboard', { replace: true });
        } catch (err) {
            setErrors(validationErrors(err));
            setError(apiErrorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="center-screen">
            <div className="card auth-card">
                <h1>Create account</h1>
                {error && !Object.keys(errors).length ? <Alert kind="error">{error}</Alert> : null}
                <form onSubmit={submit} style={{ marginTop: '1rem' }}>
                    <Field label="Name" error={errors.name?.[0]}>
                        <input className="input" value={form.name} onChange={update('name')} required />
                    </Field>
                    <Field label="Email" error={errors.email?.[0]}>
                        <input className="input" type="email" value={form.email} onChange={update('email')} required />
                    </Field>
                    <Field label="Password" error={errors.password?.[0]}>
                        <input
                            className="input"
                            type="password"
                            value={form.password}
                            onChange={update('password')}
                            required
                        />
                    </Field>
                    <Field label="Confirm password">
                        <input
                            className="input"
                            type="password"
                            value={form.password_confirmation}
                            onChange={update('password_confirmation')}
                            required
                        />
                    </Field>
                    <button className="btn btn-primary" type="submit" disabled={busy} style={{ width: '100%' }}>
                        {busy ? 'Creating…' : 'Create account'}
                    </button>
                </form>
                <p className="muted" style={{ marginTop: '1rem', fontSize: '0.85rem' }}>
                    Already registered? <Link to="/login">Sign in</Link>
                </p>
            </div>
        </div>
    );
}
