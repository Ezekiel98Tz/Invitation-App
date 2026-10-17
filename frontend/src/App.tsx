import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './lib/auth';
import { Layout } from './components/Layout';
import { ProtectedRoute } from './components/ProtectedRoute';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { LandingPage } from './pages/LandingPage';
import { DashboardPage } from './pages/DashboardPage';
import { EventPage } from './pages/EventPage';
import { PledgesPage } from './pages/PledgesPage';
import { PledgesImportPage } from './pages/PledgesImportPage';
import { PledgeDetailPage } from './pages/PledgeDetailPage';
import { CheckInPage } from './pages/CheckInPage';
import { ContactsPage } from './pages/ContactsPage';
import { ContactDetailPage } from './pages/ContactDetailPage';
import { DeliveryLabPage } from './pages/DeliveryLabPage';
import { CelebrationsPage } from './pages/CelebrationsPage';
import { CelebrationDetailPage } from './pages/CelebrationDetailPage';
import { InvitationDesignerPage } from './pages/InvitationDesignerPage';
import { PublicInvitePage } from './pages/PublicInvitePage';
import { PublicPassPage } from './pages/PublicPassPage';

export function App() {
    // The SPA is mounted at the site root. Its static assets are emitted under
    // /app/assets (see vite.config.ts) but the client router runs at "/".
    return (
        <BrowserRouter>
            <AuthProvider>
                <Routes>
                    <Route path="/" element={<LandingPage />} />
                    <Route path="/login" element={<LoginPage />} />
                    <Route path="/register" element={<RegisterPage />} />
                    <Route path="/e/:token" element={<PublicInvitePage />} />
                    <Route path="/e/:token/pass" element={<PublicPassPage />} />

                    <Route
                        element={
                            <ProtectedRoute>
                                <Layout />
                            </ProtectedRoute>
                        }
                    >
                        <Route path="/dashboard" element={<DashboardPage />} />
                        <Route path="/contacts" element={<ContactsPage />} />
                        <Route path="/contacts/:id" element={<ContactDetailPage />} />
                        <Route path="/delivery-lab" element={<DeliveryLabPage />} />
                        <Route path="/celebrations" element={<CelebrationsPage />} />
                        <Route path="/celebrations/:id" element={<CelebrationDetailPage />} />
                        <Route path="/events/:id" element={<EventPage />} />
                        <Route path="/events/:id/pledges" element={<PledgesPage />} />
                        <Route path="/events/:id/pledges/import" element={<PledgesImportPage />} />
                        <Route path="/events/:id/pledges/:pledgeId" element={<PledgeDetailPage />} />
                        <Route path="/events/:id/check-in" element={<CheckInPage />} />
                        <Route path="/events/:id/invitation" element={<InvitationDesignerPage />} />
                    </Route>

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    );
}
