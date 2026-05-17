<?php
$path = dirname(__DIR__) . '/modules/reportes_pago_usuarios.php';
$content = file_get_contents($path);
$old = <<<'OLD'
                                            <input type="hidden" name="accion" value="confirmar">
                                            <button type="submit" class="btn btn-success btn-sm" title="Confirmar">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Rechazar este pago?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="reporte_id" value="<?= $reporte['id'] ?>">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Rechazar">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </motion>
                                    <?php else: ?>
                                    <span class="text-muted">
                                        <i class="fas fa-<?= $reporte['estatus'] === 'confirmado' ? 'check-circle text-success' : 'times-circle text-danger' ?>"></i>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <!-- Botón para ver detalles -->
                                    <button type="button" class="btn btn-info btn-sm" 
                                            onclick="verDetalles(<?= htmlspecialchars(json_encode($reporte)) ?>)"
                                            title="Ver detalles">
                                        <i class="fas fa-eye"></i>
                                    </button>
OLD;
$new = <<<'NEW'
                                            <input type="hidden" name="accion" value="rechazar">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Rechazar"><i class="fas fa-ban"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
NEW;
$content = str_replace($old, $new, $content);
$content = str_replace('</motion>', '</div>', $content);
$content = str_replace('<motion class=', '<div class=', $content);
file_put_contents($path, $content);
echo "Fixed\n";
