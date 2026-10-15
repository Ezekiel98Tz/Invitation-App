import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';

export function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        await logout();
        navigate('/login', { replace: true });
    };

    return (
        <div className="app-shell">
            <header className="topbar">
                <div className="row">
                    <span className="brand">Invitation Studio</span>
                    <nav className="nav" style={{ marginLeft: '1rem' }}>
                        <NavLink to="/dashboard" className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`} end>
                            Dashboard
                        </NavLink>
                        <NavLink to="/contacts" className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}>
                            Contacts
                        </NavLink>
                        <NavLink
                            to="/delivery-lab"
                            className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}
                        >
                            Delivery Lab
                        </NavLink>
                        <NavLink
                            to="/celebrations"
                            className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}
                        >
                            Celebrations
                        </NavLink>
                    </nav>
                </div>
                <div className="nav">
                    <span className="muted" style={{ fontSize: '0.85rem' }}>
                        {user?.name}
                    </span>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={handleLogout}>
                        Log out
                    </button>
                </div>
            </header>
            <main className="container">
                <Outlet />
            </main>
        </div>
    );
}
