<?php
/**
 * EXAMPLE template for FilesAdminController::index(): copy to templates/FilesAdmin/index.php and restyle.
 * Two modes: the folder list over every registered collection, or one collection's files with an upload form.
 *
 * @var \Cake\View\View $this
 * @var array<string, array{label: string, mimes: list<string>, size: int, public: bool}> $collections
 * @var array<string, int> $counts
 * @var array{name: string, label: string, mimes: list<string>, size: int, public: bool}|null $collection
 * @var iterable<\TheMusicDev\Files\Model\Entity\StoredFile> $rows
 */
$collection = $collection ?? null;
$csrf = (string)$this->getRequest()->getAttribute('csrfToken');
?>
<h1><?= $collection === null ? 'Files' : h($collection['label']) ?></h1>
<?= $this->Flash->render() ?>

<?php if ($collection === null): ?>
    <?php if ($collections === []): ?>
        <p>No collections are registered.</p>
    <?php endif; ?>
    <ul>
        <?php foreach ($collections as $name => $definition): ?>
            <li>
                <a href="<?= $this->Url->build(['action' => 'index', $name]) ?>"><?= h($definition['label']) ?></a>
                (<?= (int)($counts[$name] ?? 0) ?>) <small><?= h($name) ?></small>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <p>
        <a href="<?= $this->Url->build(['action' => 'index']) ?>">All folders</a> &middot;
        up to <?= number_format($collection['size'] / 1024 / 1024, 1) ?> MB &middot;
        <?= $collection['mimes'] === [] ? 'any type' : h(implode(', ', $collection['mimes'])) ?>
    </p>

    <form method="post" enctype="multipart/form-data" action="<?= $this->Url->build(['action' => 'upload']) ?>">
        <input type="hidden" name="_csrfToken" value="<?= h($csrf) ?>">
        <input type="hidden" name="collection" value="<?= h($collection['name']) ?>">
        <input type="file" name="file" required>
        <button type="submit">Upload</button>
    </form>

    <?php if (((int)($counts[$collection['name']] ?? 0)) === 0): ?>
        <p>Empty folder: upload something.</p>
    <?php endif; ?>

    <ul>
        <?php foreach ($rows as $row): ?>
            <li>
                <!-- The serve route is built from the router, never typed. It runs your Files.authCallable. -->
                <a href="<?= $this->Url->build([
                    'plugin' => 'TheMusicDev/Files', 'controller' => 'Dl', 'action' => 'serve', (int)$row->get('id'),
                ]) ?>"><?= h((string)$row->get('original_filename')) ?></a>
                <small><?= h((string)$row->get('mime')) ?> &middot;
                    <?= number_format((int)$row->get('size') / 1024, 1) ?> KB</small>
                <form method="post" style="display:inline"
                    action="<?= $this->Url->build(['action' => 'delete', (int)$row->get('id')]) ?>"
                    onsubmit="return confirm('Delete this file? The binary is kept until purge.')">
                    <input type="hidden" name="_csrfToken" value="<?= h($csrf) ?>">
                    <input type="hidden" name="collection" value="<?= h($collection['name']) ?>">
                    <button type="submit">Delete</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (count($rows) > 0): ?>
        <?= $this->Paginator->numbers() ?>
    <?php endif; ?>
<?php endif; ?>
