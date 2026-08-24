# Новая привязка Content Factory к сайту и блокам

Новая привязка нужна, когда второй WordPress использует ту же общую архитектуру
и плагины, но имеет другой набор, порядок или контракт Gutenberg-блоков. PageSpec
остаётся семантическим; различия темы локализуются в manifest и реализации
`ThemeAdapterInterface`.

Предметная область не ограничена потолками. Сайт адвокатского бюро, медицинской
клиники, магазина или другой организации может иметь совершенно иные структуры
страниц, поля и блоки. `potolki-inner` является только одной реализацией, а не
шаблоном, который нужно приспосабливать ко всем сайтам.

## 1. Когда нужен новый профиль

Создавайте новый `profileId`, если меняется хотя бы одно из следующего:

- block name, обязательный дочерний block или `parent`;
- имя, тип или enum атрибута, в который записываются данные;
- допустимые semantic sections, их порядок или количество;
- root block blueprint или page template;
- обязательность изображений, ссылок, формы или других данных;
- site defaults, которые меняют структуру, а не только значения.

Новая предметная область обычно требует нового профиля даже при технически
одинаковой теме. Не переиспользуйте `service-detail`, `catalog`, `steps`, `faq`
или `cta` только потому, что эти имена уже существуют. Сначала опишите реальные
сущности домена. Например, юридическому сайту могут понадобиться page types
`legal-service`, `practice-area` и `lawyer-profile`, а также секции с опытом,
квалификацией, условиями обращения, документами или обязательным дисклеймером.
Это лишь примеры: итоговый состав выводится из контента и блоков целевого сайта.

Базовый PageSpec намеренно оставляет `pageType`, `sections[].type` и
`sections[].data` расширяемыми. Их конкретные названия, поля, обязательность,
типы, количество и порядок задаёт активный adapter manifest и его validator.
Поэтому новый профиль может иметь другой состав секций без изменения import,
hierarchy, logging и publish guard в ядре плагина.

Изменение только контента, домена, телефона или существующего theme asset не
обязательно требует нового semantic page type, но новый сайт всё равно должен
иметь отдельный `siteKey` и проверенную конфигурацию.

Не добавляйте `if (site...)` в core pipeline. Не заставляйте новый блок принимать
атрибуты старого блока и не записывайте сгенерированный HTML в PageSpec.

Для нового домена создайте отдельный регламент конвертации рядом с adapter или в
companion plugin. В нём зафиксируйте:

- поддерживаемые типы исходных документов и способы определения page type;
- карту исходных полей в semantic sections;
- доменные факты, которые запрещено додумывать;
- обязательные предупреждения, дисклеймеры и требования к проверке;
- правила ссылок, изображений, авторства и актуальности;
- критерии редакторской и, при необходимости, экспертной приёмки.

Регламент `docs/content-conversion.md` относится к приложенному потолочному набору
и профилю `potolki-inner`; переносить его доменные решения на другой сайт нельзя.

## 2. Классификация и процесс изменения

Классифицируйте изменение до редактирования кода. Смешанное изменение получает
самую строгую из подходящих категорий.

| Вид | Контракт | Версия профиля | Обязательная проверка |
| --- | --- | --- | --- |
| Визуальное | Не меняется | Без изменения | Screenshots, editor/public desktop/mobile |
| Исправление mapping/render | Не меняется | `PATCH`, если меняется adapter output | Regression, все golden fixtures, round-trip |
| Необязательное расширение | Обратно совместим | `MINOR` | Старый и новый PageSpec, Registry, Preview |
| Состав page type | Зависит от required/order/min/max | `MINOR` или `MAJOR` | Golden page, order/count, hierarchy/links |
| Breaking block contract | Несовместим | `MAJOR` или новый профиль | Real post migration, deprecated blocks, recovery |
| Новый сайт/домен | Отдельный контракт | Новый `profileId` | Полная приёмка adapter и домена |

### Визуальное изменение

Меняйте только presentation layer темы. До работы сохраните reference screenshots.
После работы проверьте те же страницы, длинные строки, изображения, focus/hover,
desktop/mobile и отсутствие layout shift. Если изменились attributes, InnerBlocks,
save markup или render input, это уже не визуальная категория.

### Исправление mapping или render

Сначала добавьте regression fixture/test. Исправляйте слой-владелец: theme render
или save в теме, semantic-to-block mapping в адаптере, общую инфраструктуру в core.
Старые PageSpec должны остаться допустимыми. Поднимите `PATCH`, когда меняются
результат adapter build, manifest или compatibility decision.

### Обратно совместимое расширение

Порядок обязателен:

1. Добавьте необязательный attribute/child behavior в тему с безопасным default.
2. Докажите, что старый сериализованный block остаётся valid и выглядит прежним.
3. Расширьте manifest schema, allowed/required data, mappings и Registry contract.
4. Расширьте validate/build, сохранив прежний результат старых PageSpec.
5. Поднимите `MINOR`, добавьте fixtures с полем и без него, выполните Preview и
   revalidate.

Новый required field без доступного default не является обратно совместимым.

### Изменение состава page type

Сначала опишите новую семантику и вручную соберите эталон в Gutenberg. Затем
обновите theme blocks, `pageTypes.occurrences`, section schemas, `rootBlueprint` и
adapter mapping. Добавьте положительный fixture всего page type и отрицательные
проверки порядка, min/max, parent, links и assets.

Добавление необязательной секции обычно `MINOR`. Новая обязательная секция,
удаление, изменение порядка или уменьшение допустимого диапазона обычно `MAJOR`
либо новый профиль, если прежние PageSpec должны продолжать обслуживаться.

### Breaking block contract

Перед изменением выгрузите перечень затронутых managed и обычных WordPress pages,
а также реальные образцы `post_content`. Выберите один путь:

- Gutenberg `deprecated` definition с `migrate()` для статического блока;
- поддержка старого и нового render contract для динамического блока;
- lossless alias в adapter manifest;
- новый `profileId`, когда семантика действительно стала другой.

Миграция обязана быть доказана тестом на реальном старом markup. Простое изменение
`block.json` или save output без deprecated/migration может превратить старые
blocks в invalid/recovery state. Alias запрещён при потере данных или изменении
смысла. Published pages массово не пересохраняйте без отдельного подтверждённого
плана и rollback.

Порядок deployment: сначала тема, способная читать старый и новый contract, затем
adapter/manifest, затем новые PageSpec. Удаление legacy support выполняется
отдельным релизом после инвентаризации и подтверждённой миграции.

### Новый сайт или домен

Выполните полный аудит из следующего раздела, создайте отдельные `siteKey` и
`profileId`, доменный conversion playbook и companion adapter. Примите по одному
черновику каждого page type до массового импорта. Существующий профиль не должен
получать условные ветки другого бизнеса.

## 3. Предварительный аудит сайта

Работу начинайте на копии/локальном окружении. Зафиксируйте:

```text
WordPress/PHP versions
active theme stylesheet/version
page template
active SEO/form plugins and versions
all root and child block names
registered attributes: name, type, enum, default
parent declarations and allowed inner blocks
dynamic render callback or static save markup
required block order and occurrence counts
theme assets and their physical paths
phone, form labels/actions, analytics goals, TOC labels
existing page paths and media attachments
```

Сверяйте `block.json`/PHP registration с фактическим
`WP_Block_Type_Registry`. DOM из браузера недостаточен: он не доказывает контракт
атрибутов и сериализации.

Для каждого нужного макета вручную соберите эталонную страницу в редакторе и
сохраните:

- дерево блоков из `parse_blocks()`;
- сериализованный `post_content`;
- публичный render и Preview;
- состояния desktop/mobile;
- обязательные атрибуты и данные, которые тема подставляет сама.

Результат аудита — таблица:

```text
semantic_type -> block_name -> attributes -> children -> source fields -> rules
```

Если блок не может без потерь выразить semantic section, проектируйте новую
секцию/версию профиля или меняйте блок темы. Не скрывайте данные в неподдерживаемом
атрибуте.

## 4. Выбор способа поставки

Для сайта, живущего отдельно от этого репозитория, используйте companion plugin:

```text
wp-content/plugins/content-factory-<site>/
  content-factory-<site>.php
  adapters/<profile-id>/manifest.json
  src/<Site>Adapter.php
  tests/
```

Он зависит от Content Factory и регистрирует адаптер:

```php
add_action(
	'content_factory_register_adapters',
	static function ( ContentFactory\Adapter\AdapterRegistry $registry ): void {
		$registry->register( new Vendor\ContentFactory\SiteAdapter() );
	}
);
```

Если адаптер должен поставляться вместе с этим плагином, добавьте manifest в
`adapters/<profile-id>/`, класс в `src/Adapter/` и явную регистрацию в
`src/Plugin.php`.

`AdapterRegistry::active()` выбирает первый адаптер, для которого
`supports_current_theme()` вернул true. Поэтому:

- для разных тем используйте уникальный stylesheet contract;
- для одинакового stylesheet добавьте стабильный site/profile marker;
- тестируйте, что на каждом сайте поддерживается ровно один адаптер;
- не полагайтесь на случайный порядок hook callbacks;
- если существующий встроенный адаптер тоже совпадает, сузьте его predicate или
  добавьте явный механизм выбора как отдельное изменение core с тестами.

## 5. Manifest как контракт

Новый manifest должен проходить
`schemas/theme-manifest-1.0.schema.json` и содержать все обязательные части:

1. `manifestSchemaVersion`, `profileId`, `profileVersion`, `siteKey`.
2. `themeCompatibility` и поддерживаемые `pageSpecVersions`.
3. `postDefaults`: page type, template и всегда `draft`.
4. `pageTypes`: допустимые секции, min/max и политика parent-link.
5. `sections`: schema semantic data и точный block mapping.
6. `rootBlueprint`: корневые блоки, порядок и количество.
7. `siteDefaults` и их версия.
8. `assets`: только существующие theme-relative paths.
9. `aliases`: только явные безопасные миграции прежних section names.
10. `policies`: ограничения rich text, resources и registry contracts.

Для каждой semantic section задайте:

- `blockName`;
- `allowedData` и `requiredData` без противоречий;
- JSON schema с `additionalProperties: false`, где возможно;
- `mappingAttributes`, реально существующие у root block;
- `childBlockName`/`childMappingAttributes`, если секция составная;
- точные registry contracts типов, enum и parent;
- occurrence limits во всех page types, где секция допустима.

Не копируйте `potolki-inner` целиком и не оставляйте неиспользуемые поля. Начните
с результатов аудита и перенесите только доказанный контракт.

### Версионирование

- `PATCH`: исправление mapping/валидации без изменения допустимого PageSpec.
- `MINOR`: обратно совместимое добавление необязательной возможности.
- `MAJOR`: удаление/переименование поля, изменение обязательности, семантики или
  результата, способное сделать прежний PageSpec несовместимым.

После любого изменения обновите `profileVersion`; новый ответ manifest даст новый
`manifestHash`. Alias допустим только когда старое и новое значение семантически
эквивалентны и миграция не теряет данные.

## 6. Реализация ThemeAdapterInterface

Адаптер обязан реализовать:

```php
interface ThemeAdapterInterface {
	public function id(): string;
	public function version(): string;
	public function supports_current_theme(): bool;
	public function manifest(): array;
	public function manifest_hash(): string;
	public function self_check(): CompatibilityReport;
	public function validate( array $spec, array $context = array() ): CompatibilityReport;
	public function build( array $spec, array $context = array() ): array;
}
```

### `supports_current_theme()`

Проверяет реальную совместимость, а не похожесть сайта. Минимум — stylesheet и
version из manifest. При общей теме для нескольких контрактов добавьте стабильный
признак сайта. Метод не должен делать сетевые запросы или менять состояние.

### `self_check()`

До импорта проверяет:

- тему и минимальную версию;
- page template;
- регистрацию каждого mapped root/child/core block;
- типы, enum и parent mapped attributes;
- физическое наличие каждого theme asset;
- обязательные плагины/функции, без которых draft будет неполным.

Ошибка self-check блокирует работу; warning допустим только для действительно
необязательной функции.

### `validate()`

Проверяет target, версию, page type, порядок/число секций, semantic data, links,
assets и невозможность потери данных. Требования:

- неизвестное поле или section type — error;
- обязательное значение не подставляется из догадки;
- проверяются только реально используемые блоки плюс обязательные root blocks;
- ошибка содержит стабильный code, JSON pointer, `sourceId`, section ID и
  ожидаемое значение;
- метод не создаёт posts, attachments и options.

### `build()`

Работает только после успешной валидации и возвращает `BlockNode[]`.

- Одна semantic field должна иметь одно понятное назначение в block attributes.
- Все ссылки проходят общий resolver, все assets — проверенный resolver адаптера.
- Экранируйте plain text и разрешайте только заявленный inline subset.
- Не теряйте target/rel, alt, parent или children молча.
- Site defaults подставляются централизованно и отражаются в manifest.
- Результат должен стабильно переживать serialize -> parse -> serialize.

Общий `GutenbergSerializer`, importers, hierarchy/link resolution, draft/publish
guards и operation logs переиспользуются. Новый адаптер не должен дублировать их.

## 7. Обязательные fixtures и тесты

Минимальный набор для новой привязки:

1. Полный положительный fixture каждого page type.
2. Fixture каждого semantic section и каждого link/asset descriptor, который
   профиль разрешает.
3. Ошибки target/site/profile и предупреждения устаревшего `generatedAgainst`,
   а также неизвестные поля/секции, min/max, order, повтор section ID/FAQ и
   неправильные heading levels.
4. Registry errors: block отсутствует, attribute отсутствует, type/enum/parent
   отличаются.
5. Link errors: неизвестный `sourceId`, 404 path, `#`, неверный anchor и
   недоступная parent dependency.
6. Asset errors: неизвестный ref, отсутствующий файл, не-image attachment и
   запрещённый external URL.
7. Golden Block Tree и Gutenberg parse/serialize/render round-trip.
8. Batch parent/child в обратном порядке, повторный import `no_change`, update
   draft и запрет перезаписи published page.
9. Self-check для совместимой и несовместимой среды.

Основной прогон:

```sh
php wp-content/plugins/content-factory/tests/run.php
```

Для companion plugin добавьте его suite и запускайте оба набора в том же
WordPress runtime. Golden fixtures проверяют контракт, но не являются шаблоном
сокращения реального SEO-текста.

## 8. Приёмка на целевом WordPress

Перед передачей адаптера:

1. Активируйте тему, Content Factory, Yoast и companion plugin.
2. Проверьте `/manifest`: выбран правильный profile/site, hash стабилен,
   `selfCheck` не содержит errors.
3. Валидируйте fixtures и реальную тестовую партию без записи.
4. Создайте по одному черновику каждого page type.
5. Проверьте editor и Preview на desktop/mobile: порядок, внешний вид, ссылки,
   изображения, форму, SEO и отсутствие invalid blocks/console errors.
6. Выполните revalidate и повторный import с ожидаемым `no_change`.
7. Удалите тестовые черновики/данные, если они не нужны пользователю.
8. Запишите `profileId`, version, hash, версии темы/плагинов и результаты тестов.

Адаптер готов только когда весь поддерживаемый semantic контракт выражается без
потерь, а несовместимые данные отклоняются до создания черновика.
