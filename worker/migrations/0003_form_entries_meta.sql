-- Pseudonymous submitter metadata for form entries (hashed IP, user agent).
ALTER TABLE form_entries ADD COLUMN ip_hash TEXT;
ALTER TABLE form_entries ADD COLUMN user_agent TEXT;
