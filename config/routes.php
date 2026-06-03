<?php
/**
 * Definición de rutas de la aplicación
 * 
 * Este archivo registra las rutas usando el Router moderno.
 * Las rutas legacy (?page=xxx) siguen funcionando por compatibilidad.
 */

use Core\Routing\Router;
use Core\Http\Response;
use Core\Middleware\AuthMiddleware;
use Core\Middleware\RateLimitMiddleware;

/**
 * @param Router $router
 */
return function (Router $router) {
    
    // =================================================================
    // RUTAS PÚBLICAS (sin autenticación)
    // =================================================================
    
    // Landing page
    $router->get('/', function() {
        include __DIR__ . '/../public/landing.php';
        return '';
    });
    
    // Login con rate limiting (5 intentos por minuto)
    $router->group(['prefix' => '/auth', 'middleware' => [new RateLimitMiddleware(5, 60)]], function($router) {
        $router->match(['GET', 'POST'], '/login', function() {
            ob_start();
            include __DIR__ . '/../public/login.php';
            return ob_get_clean() ?: '';
        });
        
        $router->get('/logout', function() {
            include __DIR__ . '/../modules/auth/logout.php';
            return '';
        });
        
        $router->match(['GET', 'POST'], '/forgot-password', function() {
            ob_start();
            include __DIR__ . '/../modules/auth/forgot_password.php';
            return ob_get_clean() ?: '';
        });

        $router->match(['GET', 'POST'], '/recover-user', function() {
            ob_start();
            include __DIR__ . '/../modules/auth/recover_user.php';
            return ob_get_clean() ?: '';
        });
        
        $router->match(['GET', 'POST'], '/reset-password', function() {
            ob_start();
            include __DIR__ . '/../modules/auth/reset_password.php';
            return ob_get_clean() ?: '';
        });

        // Formulario de registro de usuario (público; sin restricción de entidad cuando from_invitation=1)
        $router->match(['GET', 'POST'], '/register', function() {
            ob_start();
            include __DIR__ . '/../public/user_register.php';
            return ob_get_clean() ?: '';
        });

        // Túnel Fast-Track: solo Nombre, Email, Password. ID_CLUB y ENTIDAD vía token. Auto-login y redirección a inscripción.
        $router->match(['GET', 'POST'], '/register-invited', function() {
            try {
                ob_start();
                include __DIR__ . '/../modules/register_invited_delegate.php';
                return ob_get_clean() ?: '';
            } catch (Throwable $e) {
                error_log('register-invited: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                return Response::json([
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], 500);
            }
        });
    });
    
    // API pública: envío de resultados de mesa con foto de acta
    $router->post('/actions/public-score-submit', function() {
        include __DIR__ . '/../actions/public_score_submit.php';
        return '';
    });

    // Un solo enlace: /join?token=... — Formulario de registro en la misma URL; sin redirecciones a register-invited
    $router->match(['GET', 'POST'], '/join', function() {
        try {
            $GLOBALS['join_redirect_url'] = null;
            ob_start();
            include __DIR__ . '/../modules/join.php';
            $out = ob_get_clean();
            if (!empty($GLOBALS['join_redirect_url'])) {
                return Response::redirect($GLOBALS['join_redirect_url'], 302);
            }
            return $out !== '' ? $out : Response::html('<p>No se pudo cargar. <a href="/">Volver al inicio</a>.</p>');
        } catch (Throwable $e) {
            error_log('join: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Response::json(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    });
    
    // Registro de invitaciones (público)
    $router->group(['prefix' => '/invitation'], function($router) {
        $router->match(['GET', 'POST'], '/register', function() {
            ob_start();
            include __DIR__ . '/../modules/invitation_register.php';
            return ob_get_clean() ?: '';
        });
        
        $router->match(['GET', 'POST'], '/register-select', function() {
            ob_start();
            include __DIR__ . '/../modules/invitation_register_select.php';
            return ob_get_clean() ?: '';
        });

        // Tarjeta de invitación digital (pública por token)
        $router->get('/digital', function() {
            ob_start();
            include __DIR__ . '/../modules/invitacion_digital.php';
            return ob_get_clean() ?: '';
        });

        // Descarga PDF de la invitación digital (pública por token)
        $router->get('/digital/pdf', function() {
            include __DIR__ . '/../modules/invitacion_digital_pdf.php';
            return '';
        });
    });
    
    // API pública para formulario de inscripción por invitación (sin autenticación)
    $router->group(['prefix' => '/api'], function($router) {
        $router->get('/check_cedula.php', function() {
            ob_start();
            include __DIR__ . '/../public/api/check_cedula.php';
            return ob_get_clean() ?: '';
        });
        $router->get('/search_persona.php', function() {
            ob_start();
            include __DIR__ . '/../public/api/search_persona.php';
            return ob_get_clean() ?: '';
        });
    });
    
    // =================================================================
    // RUTAS PROTEGIDAS (requieren autenticación)
    // =================================================================
    
    $router->group(['middleware' => [new AuthMiddleware(['admin_general', 'admin_torneo'])]], function($router) {
        
        // Dashboard con estadísticas
        $router->get('/dashboard', function() {
            include __DIR__ . '/../modules/home.php';
            return '';
        });
        
        // API endpoints
        $router->group(['prefix' => '/api'], function($router) {
            $router->get('/tournament-stats', function() {
                include __DIR__ . '/../api/tournament_stats.php';
                return '';
            });
            
            $router->get('/search-persona', function() {
                include __DIR__ . '/../api/search_persona.php';
                return '';
            });
        });
    });
    
    // =================================================================
    // RUTAS DE ADMIN GENERAL
    // =================================================================
    
    $router->group(['prefix' => '/admin', 'middleware' => [new AuthMiddleware(['admin_general'])]], function($router) {
        
        // Gestión de usuarios
        $router->get('/users', function() {
            include __DIR__ . '/../modules/users.php';
            return '';
        });
        
        // Gestión de clubes
        $router->get('/clubs', function() {
            include __DIR__ . '/../modules/clubs.php';
            return '';
        });

        // Reporte de actividad (auditoría) — redirige a legacy para usar el layout
        $router->get('/auditoria', function() {
            require_once __DIR__ . '/../lib/app_helpers.php';
            header('Location: ' . \AppHelpers::dashboard('auditoria'));
            exit;
        });
    });
};


