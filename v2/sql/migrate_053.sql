-- fable-087 (Ömer, 19 Ağu): "uygulamaya girince bildirimi okuyacak yer yok."
-- Kullanıcının bildirimleri en son ne zaman okuduğu — okunmamış rozeti bundan hesaplanır.
ALTER TABLE users ADD COLUMN bildirim_okundu_at DATETIME NULL DEFAULT NULL;
