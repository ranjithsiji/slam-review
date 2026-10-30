<?php
/** Public site footer: contact + social links + copyright bar. */
$site = config(\Modules\Core\Config\Site::class);
?>
<footer class="site-footer d-print-none">
    <div class="site-footer-contact">
        <div class="container-xl py-5">
            <div class="row gy-4 align-items-center">
                <div class="col-12 col-md-7">
                    <h2 class="site-footer-title">Contact Us</h2>
                    <p class="mb-2">Questions about the slam, your badge or registration? Write to us.</p>
                    <p class="mb-0 h4"><?= icon('contact.email', 'me-2') ?><a href="mailto:<?= esc($site->contactEmail, 'attr') ?>"><?= esc($site->contactEmail) ?></a></p>
                </div>
                <div class="col-12 col-md-5">
                    <h3 class="h4 mb-2">Follow Luca</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($site->social as [$label, $url, $ic]): ?>
                            <a class="btn btn-footer-social" href="<?= esc($url) ?>" target="_blank" rel="noopener"><?= icon($ic, 'me-1') ?><?= esc($label) ?></a>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="site-footer-bar">
        <div class="container-xl py-3 d-flex flex-wrap justify-content-between gap-2 small">
            <span>Copyright &copy; <?= esc(date('Y')) ?> <?= esc($site->name) ?></span>
            <span>
                <a href="<?= site_url('badge') ?>" class="me-3">Get my badge</a>
                Powered by <a href="<?= esc($site->poweredByUrl) ?>" target="_blank" rel="noopener"><?= esc($site->poweredBy) ?></a>
            </span>
        </div>
    </div>
</footer>
