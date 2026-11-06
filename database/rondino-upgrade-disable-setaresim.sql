-- Non-destructive: keep historical offers/passes; stop active collection for setaresim.
UPDATE sources
SET is_enabled = 0,
    notes = 'غیرفعال از جمع‌آوری فعال؛ سوابق تاریخی حفظ شده است. منبع از رفرش/زمان‌بندی کنار گذاشته شد.'
WHERE slug = 'setaresim';
