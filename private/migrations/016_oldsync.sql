-- סימון חיובים שנוצרו מסנכרון המערכת הישנה (כדי שאפשר להריץ סנכרון חוזר בלי כפילויות)
ALTER TABLE charges ADD COLUMN src VARCHAR(12) NOT NULL DEFAULT '';
