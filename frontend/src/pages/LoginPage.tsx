import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { apiErrorMessage, validationErrors } from '../lib/api';
import { Alert, Field } from '../components/ui';

export function LoginPage() {
    const { user, loading, login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        if (!loading && user) {
            navigate('/dashboard', { replace: true });
        }
    }, [user, loading, navigate]);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        setErrors({});
        try {
            await login(email, password);
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
                <h1>Sign in</h1>
                <p className="muted" style={{ marginTop: 0 }}>
                    Manage your events and invitations.
                </p>
                {error && !Object.keys(errors).length ? <Alert kind="error">{error}</Alert> : null}
                <form onSubmit={submit} style={{ marginTop: '1rem' }}>
                    <Field label="Email" error={errors.email?.[0]}>
                        <input
                            className="input"
                            type="email"
                            autoComplete="username"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />
                    </Field>
                    <Field label="Password" error={errors.password?.[0]}>
                        <input
                            className="input"
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                    </Field>
                    <button className="btn btn-primary" type="submit" disabled={busy} style={{ width: '100%' }}>
                        {busy ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
                <p className="muted" style={{ marginTop: '1rem', fontSize: '0.85rem' }}>
                    No account? <Link to="/register">Create one</Link>
                </p>
            </div>
        </div>
    );
}
