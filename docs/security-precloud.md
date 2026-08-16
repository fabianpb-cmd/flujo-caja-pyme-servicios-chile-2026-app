# Security pre-cloud gate

- Fecha: 2026-08-13
- Scope: Laravel 13 + Blade + Bootstrap + MySQL, autenticación local email/password, multiempresa, datos financieros y personales.
- P0: 0
- P1: login sin rate limiting; usuarios inactivos podían autenticarse; credenciales seed visibles en login; faltaban headers base de hardening.
- P2: trusted proxies definitivos dependen del ambiente cloud; validaciones finales de despliegue cloud (HTTPS real, DB privada, secretos por ambiente).
- Correcciones realizadas: throttling de login por email+IP; bloqueo de usuarios inactivos; remoción de credenciales seed del login; middleware global con `nosniff`, `SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`; CSP endurecida con nonce para scripts inline compatibles con CDN; template `.env.example` endurecido para staging/producción; cookies seguras por defecto en `staging/production`; HSTS condicional solo para HTTPS en `staging/production`; trusted proxies configurables por entorno; tests `security` ampliados.
- Controles verificados: auth en rutas operacionales, aislamiento multiempresa básico, IDOR negativo en recursos sensibles, company_id derivado del usuario, code inmutable, montos calculados no confiados al request, escape Blade de texto, hash de passwords, CSRF vía middleware web, audit log funcional.
- Pendientes de deployment: `APP_KEY` exclusiva por ambiente; DB privada/no root con backups; secretos en secret manager; valores reales de `TRUSTED_PROXIES`; HTTPS/certificado/DNS reales; restricción de acceso inicial a staging; verificación manual final de CSP/CDN.
- Corrección adicional: hardening explícito de mass-assignment aplicado en modelos sensibles, con `company_id`, `code` y campos calculados protegidos; escrituras backend adaptadas a asignación explícita segura; strictness activado en local/testing para fallar ante atributos descartados silenciosamente. Estado: CERRADO.
- Corrección adicional: política explícita de sesión implementada para staging/producción con inactividad de 30 minutos, expiración absoluta de 8 horas desde autenticación, cierre al cerrar el navegador, remember-me deshabilitado, logout que invalida sesión y aviso UX previo a expiración con endpoint autenticado de mantenimiento.
- Corrección adicional: 2FA TOTP obligatoria para administradores implementada con Fortify, challenge de login, QR + confirmación, recovery codes y bloqueo de módulos hasta completar el enrollment. Estado: CERRADO.
- GO/NO-GO: APTO PARA STAGING CLOUD. NO APTO PARA PRODUCCIÓN PÚBLICA hasta cerrar controles dependientes de infraestructura cloud.
