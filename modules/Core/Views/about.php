<?php
$this->setVar('title', 'About · Kerala Science Slam');
// Programme: from scienceslam.in. Edit here if the dates or venues change.
$stages = [
    ['7 Nov', 'Regional slam', "Maharaja's College (Govt. Autonomous), Ernakulam"],
    ['14 Nov', 'Regional slam', 'Fatima Mata National College (Autonomous), Kollam'],
    ['21 Nov', 'Regional slam', "St. Mary's College (Autonomous), Thrissur"],
    ['28 Nov', 'Regional slam', 'Payyannur College, Kannur'],
    ['12 Dec', 'Final', 'NIT Calicut'],
];
$rules = [
    ['about.timer', 'Exactly ten minutes', 'Every talk gets ten minutes on stage, no more.'],
    ['about.nospeech', 'No slides, no reading', 'No slides and no notes. Posters, cards and models are welcome, and presenters bring their own.'],
    ['about.talk', 'Everyday language', 'Talk the way you would to a curious neighbour, and keep the audience engaged. Humour, props and even magic are fair play.'],
    ['about.flask', 'Any science', 'Any area of science or technology: agriculture, engineering, medicine, mathematics and more.'],
];
?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>

<?= $this->section('hero') ?>
<section class="site-hero site-hero-small">
    <div class="site-hero-shade">
        <div class="container-xl text-center py-5">
            <p class="site-eyebrow mb-1">About</p>
            <h1 class="site-hero-title mb-2">Science, told in ten minutes</h1>
            <p class="site-hero-lead mx-auto mb-0">Kerala Science Slam brings researchers and the public face to face: no jargon, no slides, just a good story about real science.</p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="row gy-4 align-items-center mb-5">
    <div class="col-12 col-lg-7">
        <h2 class="h1 mb-3">What is a Science Slam?</h2>
        <p class="lead">A Science Slam is a worldwide event where young researchers explain their own work to a live audience, in plain language, in ten minutes.</p>
        <p>The aim is to take new discoveries and research to ordinary people, without middlemen; to help researchers learn to explain their work simply and with humour; and to make science popular. What is judged is not how important the research is, but how clearly, memorably and engagingly it is explained.</p>
        <p class="mb-0">Slams are held in public places such as cultural centres, theatres and libraries, so scientists step out of the ivory tower and into everyday culture, and the audience gets to be part of the judging.</p>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card card-accent accent-orange">
            <div class="card-body">
                <h3 class="h3 mb-3"><?= icon('about.people', 'me-2') ?>Who can take part?</h3>
                <p class="mb-2">Anyone doing original research in science: research scholars, project fellows, postdoctoral fellows, research investigators and associates at recognised research institutions.</p>
                <p class="mb-0 text-secondary small">The audience is mostly the general public, together with students, teachers, guides and science communicators.</p>
            </div>
        </div>
    </div>
</section>

<section class="mb-5">
    <h2 class="h1 mb-3">The rules of the stage</h2>
    <div class="row row-cards">
        <?php foreach ($rules as [$ic, $title, $text]): ?>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card h-100 card-accent accent-blue"><div class="card-body">
                    <span class="avatar avatar-lg bg-orange-lt text-orange mb-3"><?= icon($ic, 'icon-lg') ?></span>
                    <h3 class="h4"><?= esc($title) ?></h3>
                    <p class="text-secondary mb-0"><?= esc($text) ?></p>
                </div></div>
            </div>
        <?php endforeach ?>
    </div>
</section>

<section class="mb-5">
    <h2 class="h1 mb-1">Two stages</h2>
    <p class="text-secondary mb-3">Four regional slams, then the final.</p>
    <ol class="about-timeline list-unstyled mb-0">
        <?php foreach ($stages as [$date, $kind, $where]): ?>
            <li class="about-timeline-item<?= $kind === 'Final' ? ' is-final' : '' ?>">
                <span class="about-timeline-date"><?= esc($date) ?></span>
                <span class="about-timeline-body"><strong><?= esc($kind) ?></strong><br><span class="text-secondary"><?= esc($where) ?></span></span>
                <?php if ($kind === 'Final'): ?><?= icon('about.trophy', 'icon-lg text-orange ms-auto') ?><?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</section>

<section class="mb-5">
    <div class="card card-accent accent-green">
        <div class="card-body">
            <h2 class="h1 mb-3"><?= icon('about.vote', 'me-2') ?>How the audience decides</h2>
            <p>The talks are judged by the public, by students and by subject experts. At the venue it works like this:</p>
            <ol class="about-steps">
                <li><strong>Check in.</strong> Scan the QR code on your badge, or type your badge ID, and confirm it is you. This is open while the gates are open.</li>
                <li><strong>Review each talk.</strong> While a talk's review window is open, your phone shows two short questions, each marked out of 5. Answer both and submit.</li>
                <li><strong>Edit at the end.</strong> After the last talk you get a short window to change any of your own marks.</li>
                <li><strong>Marks are combined fairly.</strong> General audience reviewers, a randomly drawn group of audience representatives and the judges each count with a set weight. Judges look at communication, engagement, depth and audience response.</li>
            </ol>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a class="btn btn-slam" href="<?= site_url('checkin') ?>"><?= icon('qr.scan') ?>Check in</a>
                <a class="btn" href="<?= site_url('registered') ?>"><?= icon('person.badge') ?>Am I registered?</a>
                <a class="btn" href="<?= site_url('slams') ?>"><?= icon('nav.schedule') ?>See the schedule</a>
            </div>
        </div>
    </div>
</section>

<section class="mb-4">
    <h2 class="h1 mb-3">Who organises it</h2>
    <div class="row gy-4">
        <div class="col-12 col-md-6">
            <p>Kerala Science Slam is organised by <strong>Luca</strong>, the science portal of the Kerala Sastra Sahitya Parishad (KSSP), the state's people's science movement, together with research and educational institutions across Kerala.</p>
        </div>
        <div class="col-12 col-md-6">
            <p>The cash awards for the winners are sponsored by the education initiative <strong>Curiefy</strong>.</p>
            <p class="mb-0">Questions? Write to <a href="mailto:<?= esc(config(\Modules\Core\Config\Site::class)->contactEmail, 'attr') ?>"><?= esc(config(\Modules\Core\Config\Site::class)->contactEmail) ?></a>.</p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('beforefooter') ?>
<section class="site-partners" aria-label="Organisers and supporters">
    <div class="container-xl py-4 text-center">
        <img src="<?= base_url('brand/logos-final.webp') ?>" alt="Organisers and supporters of Kerala Science Slam" class="site-partners-img" width="1200" height="413" loading="lazy">
    </div>
</section>
<?= $this->endSection() ?>
