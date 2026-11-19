-- "Main concerns" quick select, asked only after a short or broken night.
ALTER TABLE entries ADD COLUMN concerns TEXT NOT NULL DEFAULT '[]';
