import { Navigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { LoadingBlock } from './ui';

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="center-screen">
                <LoadingBlock label="Checking session…" />
            </div>
        );
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    return <>{children}</>;
}
