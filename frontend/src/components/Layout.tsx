import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import type { ReactNode } from 'react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { isCapabilityEnabled } from '../lib/capabilities';
import type { EventItem, User } from '../lib/types';

type IconName =
    | 'calendar'
    | 'celebration'
    | 'contacts'
    | 'delivery'
    | 'guests'
    | 'invitation'
    | 'deliveries'
    | 'design'
    | 'checkIn'
    | 'pledges'
    | 'profile'
    | 'staff'
    | 'audit'
    | 'logout'
    | 'menu'
    | 'close'
    | 'collapse';

export interface WorkspaceLayoutContext {
    updateEventContext: (event: EventItem) => void;
}

function Icon({ name }: { name: IconName }) {
    const paths: Record<IconName, ReactNode> = {
        calendar: <><rect x="3.5" y="5" width="17" height="15.5" rx="2" /><path d="M7.5 3.5v3M16.5 3.5v3M3.5 9.5h17M7.5 13h3M13.5 13h3M7.5 16.5h3" /></>,
        celebration: <><rect x="4" y="4.5" width="12.5" height="12.5" rx="2" /><path d="M8 8.5h4.5M8 12h4.5M8 17h7.5a2 2 0 0 0 2-2V7" /></>,
        contacts: <><circle cx="9" cy="8" r="3" /><path d="M3.5 19v-1.2A4.8 4.8 0 0 1 8.3 13h1.4a4.8 4.8 0 0 1 4.8 4.8V19zM16 5.5a3 3 0 0 1 0 5.8M17 13.2a4.5 4.5 0 0 1 3.5 4.4V19h-3" /></>,
        delivery: <><path d="m21 3-7.2 18-3.9-7.9L2 9.2 21 3Z" /><path d="m9.9 13.1 4.7-4.7" /></>,
        guests: <><circle cx="9" cy="8" r="3" /><path d="M3.5 19v-1.2A4.8 4.8 0 0 1 8.3 13h1.4a4.8 4.8 0 0 1 4.8 4.8V19zM16 8h4M18 6v4" /></>,
        invitation: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></>,
        deliveries: <><path d="M4 8.5h16l-1.5 11h-13L4 8.5Z" /><path d="M8 8.5V6a4 4 0 0 1 8 0v2.5M12 11v5m-2-2 2 2 2-2" /></>,
        design: <><path d="m4 16.5-.8 4.3 4.3-.8L19 8.5 15.5 5 4 16.5Z" /><path d="m13.8 6.7 3.5 3.5M4 20.8h16" /></>,
        checkIn: <><path d="M8 4H5a1 1 0 0 0-1 1v3M16 4h3a1 1 0 0 1 1 1v3M4 16v3a1 1 0 0 0 1 1h3M20 16v3a1 1 0 0 1-1 1h-3" /><path d="M8 12h8M12 8v8" /></>,
        pledges: <><path d="M6 3.5h9l3 3V20a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" /><path d="M14.5 3.5V7H18M8 11h8M8 14.5h5M8 18h7" /></>,
        profile: <><circle cx="12" cy="8" r="3.2" /><path d="M5 20v-1.3a7 7 0 0 1 14 0V20z" /></>,
        staff: <><circle cx="9" cy="8" r="2.8" /><circle cx="17" cy="9" r="2.2" /><path d="M3.5 19v-1.2A4.5 4.5 0 0 1 8 13.3h2a4.5 4.5 0 0 1 4.5 4.5V19zM15.5 14a4 4 0 0 1 5 3.8V19h-3" /></>,
        audit: <><path d="M7 4h10a2 2 0 0 1 2 2v14H5V6a2 2 0 0 1 2-2Z" /><path d="M9 4V2.8h6V4M8.5 9h7M8.5 12.5h7M8.5 16h4" /></>,
        logout: <><path d="M10 4H5.5a1.5 1.5 0 0 0-1.5 1.5v13A1.5 1.5 0 0 0 5.5 20H10M14 8l4 4-4 4M8 12h10" /></>,
        menu: <><path d="M4 6.5h16M4 12h16M4 17.5h16" /></>,
        close: <><path d="m6 6 12 12M18 6 6 18" /></>,
        collapse: <><path d="m14.5 5-7 7 7 7" /></>,
    };

    return (
        <svg
            className="workspace-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
        >
            {paths[name]}
        </svg>
    );
}

type CurrentState = 'page' | 'location' | undefined;

interface NavigationItemProps {
    label: string;
    icon: IconName;
    current?: CurrentState;
    onNavigate?: () => void;
    to?: string;
    href?: string;
}

function NavigationItem({ label, icon, current, onNavigate, to, href }: NavigationItemProps) {
    const className = `workspace-nav-link${current ? ' is-active' : ''}`;
    const content = (
        <>
            <Icon name={icon} />
            <span className="workspace-nav-label">{label}</span>
        </>
    );
    const sharedProps = {
        className,
        title: label,
        'aria-label': label,
        'aria-current': current,
        onClick: onNavigate,
    } as const;

    return href ? (
        <a href={href} {...sharedProps}>
            {content}
        </a>
    ) : (
        <Link to={to ?? '/dashboard'} {...sharedProps}>
            {content}
        </Link>
    );
}

interface NavigationContentProps {
    user: User;
    eventId: string | null;
    event: EventItem | null;
    eventLoading: boolean;
    pathname: string;
    hash: string;
    collapsed: boolean;
    idSuffix: string;
    onNavigate?: () => void;
    onLogout: () => void;
}

function NavigationContent({
    user,
    eventId,
    event,
    eventLoading,
    pathname,
    hash,
    collapsed,
    idSuffix,
    onNavigate,
    onLogout,
}: NavigationContentProps) {
    const eventBase = eventId ? `/events/${eventId}` : null;
    const isEventRoute = eventBase !== null;
    const eventName = event?.title ?? (eventLoading ? 'Loading event…' : 'Current event');
    const admin = user.is_admin;
    const hiddenHeadingClass = collapsed ? ' visually-hidden' : '';

    const isContacts = pathname === '/contacts' || pathname.startsWith('/contacts/');
    const isCelebrations = pathname === '/celebrations' || pathname.startsWith('/celebrations/');
    const isEventHome = pathname === '/dashboard';
    const isEventWorkspace = isEventRoute;

    const currentEventTab =
        eventBase && pathname === eventBase
            ? hash === '#invitations'
                ? 'invitations'
                : hash === '#deliveries'
                  ? 'deliveries'
                  : 'guests'
            : null;

    return (
        <>
            {idSuffix === 'desktop' ? (
                <Link
                    className="workspace-brand"
                    to="/dashboard"
                    onClick={onNavigate}
                    aria-label="Invitation Studio — Events"
                    title={collapsed ? 'Invitation Studio' : undefined}
                >
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false">
                        <path d="M5.5 7.5a2 2 0 0 1 2-2h6.8a4 4 0 0 1 4 4V26a3.5 3.5 0 0 0-3.5-3.5H7.5a2 2 0 0 1-2-2v-13Z" />
                        <path d="M26.5 7.5a2 2 0 0 0-2-2h-6.2M18.3 9.5V26a3.5 3.5 0 0 1 3.5-3.5h2.7a2 2 0 0 0 2-2v-13Z" />
                        <path d="M9 10h4M9 13.5h4M21 10h1" />
                    </svg>
                    <span className="workspace-brand-name">Invitation Studio</span>
                </Link>
            ) : null}

            <nav className="workspace-nav" aria-label="Workspace">
                <h2 className={`workspace-nav-heading${hiddenHeadingClass}`}>Workspace</h2>
                <NavigationItem
                    to="/dashboard"
                    label="Events"
                    icon="calendar"
                    current={isEventHome ? 'page' : isEventWorkspace ? 'location' : undefined}
                    onNavigate={onNavigate}
                />
                <NavigationItem
                    to="/celebrations"
                    label="Celebrations"
                    icon="celebration"
                    current={pathname === '/celebrations' ? 'page' : isCelebrations ? 'location' : undefined}
                    onNavigate={onNavigate}
                />
                <NavigationItem
                    to="/contacts"
                    label="Contacts"
                    icon="contacts"
                    current={pathname === '/contacts' ? 'page' : isContacts ? 'location' : undefined}
                    onNavigate={onNavigate}
                />
                <NavigationItem
                    to="/delivery-lab"
                    label="Delivery Lab"
                    icon="delivery"
                    current={pathname === '/delivery-lab' ? 'page' : undefined}
                    onNavigate={onNavigate}
                />
            </nav>

            {eventBase ? (
                <section className="workspace-event-nav" aria-labelledby={`event-nav-title-${idSuffix}`}>
                    <h2 className={`workspace-nav-heading${hiddenHeadingClass}`} id={`event-nav-title-${idSuffix}`}>
                        Event
                    </h2>
                    {!collapsed ? (
                        <p className="workspace-event-name" title={event?.title}>
                            {eventName}
                        </p>
                    ) : null}
                    <nav className="workspace-nav" aria-label={`${eventName} operations`}>
                        <NavigationItem
                            to={`${eventBase}#guests`}
                            label="Guests"
                            icon="guests"
                            current={currentEventTab === 'guests' ? 'page' : undefined}
                            onNavigate={onNavigate}
                        />
                        <NavigationItem
                            to={`${eventBase}#invitations`}
                            label="Invitations"
                            icon="invitation"
                            current={currentEventTab === 'invitations' ? 'page' : undefined}
                            onNavigate={onNavigate}
                        />
                        <NavigationItem
                            to={`${eventBase}#deliveries`}
                            label="Deliveries"
                            icon="deliveries"
                            current={currentEventTab === 'deliveries' ? 'page' : undefined}
                            onNavigate={onNavigate}
                        />
                        <NavigationItem
                            to={`${eventBase}/invitation`}
                            label="Invitation design"
                            icon="design"
                            current={pathname === `${eventBase}/invitation` ? 'page' : undefined}
                            onNavigate={onNavigate}
                        />
                        {isCapabilityEnabled(event, 'check_in') ? (
                            <NavigationItem
                                to={`${eventBase}/check-in`}
                                label="Check-in"
                                icon="checkIn"
                                current={pathname === `${eventBase}/check-in` ? 'page' : undefined}
                                onNavigate={onNavigate}
                            />
                        ) : null}
                        {isCapabilityEnabled(event, 'contributions') ? (
                            <NavigationItem
                                to={`${eventBase}/pledges`}
                                label="Pledges"
                                icon="pledges"
                                current={pathname.startsWith(`${eventBase}/pledges`) ? 'page' : undefined}
                                onNavigate={onNavigate}
                            />
                        ) : null}
                    </nav>
                </section>
            ) : null}

            {admin ? (
                <nav className="workspace-admin-nav workspace-nav" aria-label="Administration">
                    <h2 className={`workspace-nav-heading${hiddenHeadingClass}`}>Administration</h2>
                    <NavigationItem href="/staff" label="Staff" icon="staff" onNavigate={onNavigate} />
                    <NavigationItem href="/audit-logs" label="Audit Log" icon="audit" onNavigate={onNavigate} />
                </nav>
            ) : null}

            <div className="workspace-sidebar-footer">
                <div className="workspace-account-summary">
                    <span className="workspace-avatar" aria-hidden="true">
                        {user.name.trim().charAt(0).toUpperCase()}
                    </span>
                    <span className="workspace-account-copy">
                        <span className="workspace-account-name" title={user.name}>{user.name}</span>
                        <span className="workspace-account-role">
                            {user.is_admin ? 'Administrator' : user.is_staff ? 'Staff' : user.role}
                        </span>
                    </span>
                </div>
                <nav className="workspace-nav workspace-account-nav" aria-label="Account">
                    <NavigationItem href="/profile" label="Profile" icon="profile" onNavigate={onNavigate} />
                    <button type="button" className="workspace-nav-link workspace-logout" onClick={onLogout}>
                        <Icon name="logout" />
                        <span className="workspace-nav-label">Sign out</span>
                    </button>
                </nav>
            </div>
        </>
    );
}

function eventSectionLabel(pathname: string, hash: string, eventBase: string): string {
    if (pathname === eventBase) {
        if (hash === '#invitations') return 'Invitations';
        if (hash === '#deliveries') return 'Deliveries';
        return 'Guests';
    }
    if (pathname === `${eventBase}/invitation`) return 'Invitation design';
    if (pathname === `${eventBase}/check-in`) return 'Check-in';
    if (pathname === `${eventBase}/pledges/import`) return 'Import pledges';
    if (pathname.startsWith(`${eventBase}/pledges/`)) return 'Pledge details';
    if (pathname.startsWith(`${eventBase}/pledges`)) return 'Pledges';
    return 'Event';
}

export function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const mobileToggleRef = useRef<HTMLButtonElement>(null);
    const mobilePanelRef = useRef<HTMLDivElement>(null);
    const locationKey = `${location.pathname}${location.search}${location.hash}`;
    const previousLocationKey = useRef(locationKey);
    const eventId = /^\/events\/([^/]+)/.exec(location.pathname)?.[1] ?? null;

    const [eventRecord, setEventRecord] = useState<{ id: string; event: EventItem } | null>(null);
    const [eventLoading, setEventLoading] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    const [mobileNavigationOpen, setMobileNavigationOpen] = useState(false);

    const event = eventId && eventRecord?.id === eventId ? eventRecord.event : null;
    const eventBase = eventId ? `/events/${eventId}` : null;
    const eventTitle = event?.title ?? (eventLoading ? 'Loading event…' : 'Event');

    useEffect(() => {
        if (!eventId) {
            setEventRecord(null);
            setEventLoading(false);
            return;
        }

        const controller = new AbortController();
        setEventRecord(null);
        setEventLoading(true);

        void api
            .get<EventItem>(`/api/v1/events/${eventId}`, { signal: controller.signal })
            .then(({ data }) => setEventRecord({ id: eventId, event: data }))
            .catch(() => {
                if (!controller.signal.aborted) setEventRecord(null);
            })
            .finally(() => {
                if (!controller.signal.aborted) setEventLoading(false);
            });

        return () => controller.abort();
    }, [eventId]);

    useEffect(() => {
        if (previousLocationKey.current === locationKey) return;
        previousLocationKey.current = locationKey;
        if (!mobileNavigationOpen) return;
        setMobileNavigationOpen(false);
        mobileToggleRef.current?.focus();
    }, [locationKey, mobileNavigationOpen]);

    useEffect(() => {
        if (!mobileNavigationOpen) return;
        mobilePanelRef.current
            ?.querySelector<HTMLButtonElement>('.workspace-mobile-panel-head button')
            ?.focus();
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMobileNavigationOpen(false);
                mobileToggleRef.current?.focus();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [mobileNavigationOpen]);

    const closeMobileNavigation = () => {
        setMobileNavigationOpen(false);
        mobileToggleRef.current?.focus();
    };

    const updateEventContext = useCallback((updatedEvent: EventItem) => {
        setEventRecord({ id: String(updatedEvent.id), event: updatedEvent });
        setEventLoading(false);
    }, []);

    const handleLogout = async () => {
        await logout();
        navigate('/login', { replace: true });
    };

    if (!user) return null;

    const navigationProps = {
        user,
        eventId,
        event,
        eventLoading,
        pathname: location.pathname,
        hash: location.hash,
        onLogout: () => void handleLogout(),
    };

    return (
        <>
            <a className="workspace-skip-link" href="#main-content">
                Skip to main content
            </a>
            <div className={`workspace-shell${collapsed ? ' workspace-shell--collapsed' : ''}`}>
                <aside className="workspace-sidebar" aria-label="Application navigation">
                    <NavigationContent
                        {...navigationProps}
                        collapsed={collapsed}
                        idSuffix="desktop"
                    />
                    <button
                        type="button"
                        className="workspace-collapse-toggle"
                        aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                        aria-expanded={!collapsed}
                        title={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                        onClick={() => setCollapsed((value) => !value)}
                    >
                        <Icon name="collapse" />
                        <span>{collapsed ? 'Expand' : 'Collapse'}</span>
                    </button>
                </aside>

                <div className="workspace-main-column">
                    <header className={`workspace-topbar${eventId ? ' workspace-topbar--event' : ''}`}>
                        <div className="workspace-topbar-start">
                            <button
                                ref={mobileToggleRef}
                                type="button"
                                className="workspace-mobile-toggle"
                                aria-expanded={mobileNavigationOpen}
                                aria-controls="mobile-workspace-navigation"
                                aria-label={mobileNavigationOpen ? 'Close navigation' : 'Open navigation'}
                                onClick={() => setMobileNavigationOpen((open) => !open)}
                            >
                                <Icon name={mobileNavigationOpen ? 'close' : 'menu'} />
                                <span>{mobileNavigationOpen ? 'Close' : 'Menu'}</span>
                            </button>
                            <Link to="/dashboard" className="workspace-mobile-brand" aria-label="Invitation Studio — Events">
                                <span className="workspace-brand-mark" aria-hidden="true">
                                    <Icon name="calendar" />
                                </span>
                                <span>Invitation Studio</span>
                            </Link>
                            {eventBase ? (
                                <nav className="workspace-breadcrumbs" aria-label="Breadcrumb">
                                    <Link to="/dashboard">Events</Link>
                                    <span aria-hidden="true">/</span>
                                    <Link to={eventBase}>{eventTitle}</Link>
                                    <span aria-hidden="true">/</span>
                                    <span aria-current="page">{eventSectionLabel(location.pathname, location.hash, eventBase)}</span>
                                </nav>
                            ) : (
                                <span className="workspace-context-label">Workspace</span>
                            )}
                        </div>
                        <span className="workspace-topbar-user" title={user.name}>{user.name}</span>
                    </header>

                    <div
                        ref={mobilePanelRef}
                        className="workspace-mobile-panel"
                        id="mobile-workspace-navigation"
                        hidden={!mobileNavigationOpen}
                    >
                        <div className="workspace-mobile-panel-head">
                            <span className="workspace-mobile-panel-title">Navigation</span>
                            <button type="button" className="btn btn-ghost" onClick={closeMobileNavigation}>
                                <Icon name="close" />
                                <span>Close menu</span>
                            </button>
                        </div>
                        <NavigationContent
                            {...navigationProps}
                            collapsed={false}
                            idSuffix="mobile"
                            onNavigate={closeMobileNavigation}
                        />
                    </div>

                    <main id="main-content" className="container workspace-content" tabIndex={-1}>
                        <Outlet context={{ updateEventContext } satisfies WorkspaceLayoutContext} />
                    </main>
                </div>
            </div>
        </>
    );
}
