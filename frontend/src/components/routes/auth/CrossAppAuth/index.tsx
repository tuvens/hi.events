import {useEffect, useRef, useState} from "react";
import {useNavigate} from "react-router-dom";
import {Alert, Center, Loader, Stack, Text} from "@mantine/core";
import {t} from "@lingui/macro";
import {api} from "../../../../api/client.ts";

/**
 * Landing route for Tuvens SSO: /auth/cross-app#code=...&next=...
 *
 * The one-time authorization code arrives in the URL fragment so it is never
 * sent to any server in a request line. It is read once, scrubbed from the
 * address bar immediately, and exchanged via a POST body for a hi.events
 * session cookie.
 */

const DEFAULT_NEXT = "/manage/events";

const safeNextPath = (next: string | null): string => {
    // Internal, absolute-path redirects only — anything else could bounce the
    // freshly authenticated session to a foreign origin.
    if (next && next.startsWith("/") && !next.startsWith("//")) {
        return next;
    }
    return DEFAULT_NEXT;
};

const CrossAppAuth = () => {
    const navigate = useNavigate();
    const [error, setError] = useState<string | null>(null);
    const startedRef = useRef(false);

    useEffect(() => {
        if (startedRef.current) {
            return;
        }
        startedRef.current = true;

        const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ""));
        const code = fragment.get("code");
        const next = safeNextPath(fragment.get("next"));

        // Scrub the code from the address bar (and browser history) before
        // doing anything else.
        window.history.replaceState(null, "", window.location.pathname + window.location.search);

        if (!code) {
            setError(t`This sign-in link is missing its authorization code. Please start again from Tuvens.`);
            return;
        }

        api.post("auth/cross-app/validate", {code})
            .then(() => navigate(next, {replace: true}))
            .catch(() => {
                setError(t`This sign-in link is invalid or has expired. Please start again from Tuvens.`);
            });
    }, [navigate]);

    return (
        <Center style={{minHeight: "60vh"}}>
            <Stack align="center" gap="md">
                {error
                    ? (
                        <Alert color="red" title={t`Sign-in failed`}>
                            <Text>{error}</Text>
                        </Alert>
                    )
                    : (
                        <>
                            <Loader/>
                            <Text>{t`Signing you in with Tuvens…`}</Text>
                        </>
                    )}
            </Stack>
        </Center>
    );
};

export default CrossAppAuth;
