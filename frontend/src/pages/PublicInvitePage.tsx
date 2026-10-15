import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api, apiErrorMessage, validationErrors } from '../lib/api';
import type { PublicInvite } from '../lib/types';
import { Alert, LoadingBlock } from '../components/ui';
import { TemplatePreview } from '../components/TemplatePreview';

export function PublicInvitePage() {
    const { token } = useParams<{ token: string }>();
    const [invite, setInvite] = useState<PublicInvite | null>(null);
    const [loading, setLoading] = useState(true);
    const [notFound, setNotFound] = useState(false);
    const [expired, setExpired] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [status, setStatus] = useState<'accepted' | 'declined'>('accepted');
    const [attendingCount, setAttendingCount] = useState(1);
    const [answers, setAnswers] = useState<Record<string, unknown>>({});
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [confirmed, setConfirmed] = useState(false);

    useEffect(() => {
        const load = async () => {
            try {
                const { data } = await api.get<PublicInvite>(`/api/v1/public/invites/${token}`);
                setInvite(data);
                if (data.rsvp) {
                    setStatus(data.guest.status === 'declined' ? 'declined' : 'accepted');
                    setAttendingCount(data.rsvp.attending_count || 1);
                    setAnswers((data.rsvp.answers as Record<string, unknown>) ?? {});
                }
            } catch (err: unknown) {
                const statusCode = (err as { response?: { status?: number } })?.response?.status;
                if (statusCode === 410) setExpired(true);
                else if (statusCode === 404) setNotFound(true);
                else setError(apiErrorMessage(err));
            } finally {
                setLoading(false);
            }
        };
        void load();
    }, [token]);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setError(null);
        try {
            const payload: Record<string, unknown> = { status };
            if (status === 'accepted' && invite?.settings.allow_plus_ones) {
                payload.attending_count = attendingCount;
            }
            if (status === 'accepted') {
                payload.answers = answers;
            }
            await api.post(`/api/v1/public/invites/${token}`, payload);
            setConfirmed(true);
        } catch (err) {
            setErrors(validationErrors(err));
            if (!Object.keys(validationErrors(err)).length) {
                setError(apiErrorMessage(err));
            }
        } finally {
            setBusy(false);
        }
    };

    if (loading) return <div className="center-screen"><LoadingBlock label="Loading invitation…" /></div>;
    if (notFound) return <div className="center-screen"><div className="card"><h2>Invitation not found</h2></div></div>;
    if (expired) return <div className="center-screen"><div className="card"><h2>This invitation has closed</h2><p className="muted">RSVPs are no longer accepted.</p></div></div>;
    if (!invite) return <div className="center-screen"><Alert kind="error">{error ?? 'Unable to load invitation.'}</Alert></div>;

    const design = invite.settings.design;

    return (
        <div className="center-screen" style={{ alignItems: 'flex-start', paddingTop: '2rem' }}>
            <div className="stack" style={{ width: '100%', maxWidth: 640 }}>
                <TemplatePreview event={invite.event} design={design} guestName={invite.guest.name} />

                <div className="card">
                    {confirmed ? (
                        <Alert kind="success">
                            Thank you — your response ({status === 'accepted' ? 'attending' : 'not attending'}) has been recorded.
                        </Alert>
                    ) : (
                        <form onSubmit={submit}>
                            <h3>Your RSVP</h3>
                            <div className="row" style={{ marginBottom: '0.75rem' }}>
                                <label className="row">
                                    <input
                                        type="radio"
                                        name="status"
                                        checked={status === 'accepted'}
                                        onChange={() => setStatus('accepted')}
                                    />
                                    <span>Yes, I will attend</span>
                                </label>
                                <label className="row">
                                    <input
                                        type="radio"
                                        name="status"
                                        checked={status === 'declined'}
                                        onChange={() => setStatus('declined')}
                                    />
                                    <span>No, I cannot attend</span>
                                </label>
                            </div>
                            {errors.status?.[0] ? <div className="error-text">{errors.status[0]}</div> : null}

                            {status === 'accepted' && invite.settings.allow_plus_ones ? (
                                <div className="field">
                                    <label className="label">Number attending</label>
                                    <input
                                        className="input"
                                        type="number"
                                        min={1}
                                        max={5}
                                        value={attendingCount}
                                        onChange={(e) => setAttendingCount(Number(e.target.value))}
                                    />
                                    {errors.attending_count?.[0] ? (
                                        <div className="error-text">{errors.attending_count[0]}</div>
                                    ) : null}
                                </div>
                            ) : null}

                            {status === 'accepted' && invite.settings.custom_questions.length > 0
                                ? invite.settings.custom_questions.map((q) => (
                                      <div className="field" key={q.key}>
                                          <label className="label">
                                              {q.key}
                                              {q.required ? ' *' : ''}
                                          </label>
                                          {q.type === 'choice' ? (
                                              <select
                                                  className="select"
                                                  value={(answers[q.key] as string) ?? ''}
                                                  onChange={(e) => setAnswers({ ...answers, [q.key]: e.target.value })}
                                              >
                                                  <option value="">Select…</option>
                                                  {(q.options ?? []).map((opt) => (
                                                      <option key={opt} value={opt}>
                                                          {opt}
                                                      </option>
                                                  ))}
                                              </select>
                                          ) : q.type === 'boolean' ? (
                                              <input
                                                  type="checkbox"
                                                  checked={Boolean(answers[q.key])}
                                                  onChange={(e) => setAnswers({ ...answers, [q.key]: e.target.checked })}
                                              />
                                          ) : (
                                              <input
                                                  className="input"
                                                  type={q.type === 'number' ? 'number' : 'text'}
                                                  value={(answers[q.key] as string) ?? ''}
                                                  onChange={(e) => setAnswers({ ...answers, [q.key]: e.target.value })}
                                              />
                                          )}
                                          {errors[`answers.${q.key}`]?.[0] ? (
                                              <div className="error-text">{errors[`answers.${q.key}`][0]}</div>
                                          ) : null}
                                      </div>
                                  ))
                                : null}

                            {error ? <Alert kind="error">{error}</Alert> : null}

                            <button className="btn btn-primary" type="submit" disabled={busy} style={{ width: '100%' }}>
                                {busy ? 'Submitting…' : 'Submit RSVP'}
                            </button>
                        </form>
                    )}
                </div>
            </div>
        </div>
    );
}
