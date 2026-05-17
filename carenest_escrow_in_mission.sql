-- Optional: allow marking escrow as "in mission" after Pal check-in (run once in phpMyAdmin if you want this status).
-- If you skip this, the app still reserves points under status "Locked" until completion.

ALTER TABLE escrow
MODIFY COLUMN status ENUM(
    'Locked',
    'In_Mission',
    'Released_To_Pal',
    'Returned_To_Senior',
    'Disputed'
) NOT NULL DEFAULT 'Locked';
