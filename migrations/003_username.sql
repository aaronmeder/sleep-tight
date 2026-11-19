-- Logins now ask for a username (so password managers have something to fill).
-- Single user so far; an install without one gets the owner's.
INSERT OR IGNORE INTO settings (key, value) VALUES ('username', 'Ronson');
