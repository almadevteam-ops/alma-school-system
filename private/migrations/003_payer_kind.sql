ALTER TABLE families ADD COLUMN payer_kind VARCHAR(10) NOT NULL DEFAULT 'parents';
UPDATE families SET payer_kind = 'org' WHERE payer_id IS NOT NULL;
