# Fish&Trip: Figma-ready макет

## Фрейми

| Фрейм | Розмір | Сітка |
| --- | --- | --- |
| Desktop Homepage | 1440 x 1320 px | container 1180 px, 12 колонок, gutter 20 px |
| Mobile Homepage | 375 x 812 px | поля 12 px, 4 колонки, gutter 8 px |

## Стилі

- Font: Inter / system sans-serif
- Primary: `#405D18`
- Primary dark: `#1C2A0F`
- Background: `#F7F7F3`
- Card: `#FFFFFF`
- Border: `#E4E7E0`
- Main text: `#1D2418`
- Muted text: `#75806F`
- Radius: `5 px` buttons, `8 px` cards, `9 px` promo

## Desktop шари

1. `Header / Topbar`
2. `Header / Main`
3. `Hero`
4. `Features`
5. `Categories`
6. `Products`
7. `Promo`

## Mobile шари

1. `Header`
2. `Hero`
3. `Categories / Featured 2`
4. `Products / Popular 2`
5. `Why us`
6. `Bottom navigation`

## Компоненти

- `Button / Primary`
- `Header / Desktop`
- `Header / Mobile`
- `Feature item`
- `Category card`
- `Product card`
- `Bottom nav item`
- `Promo benefit`

## Прототипування

- `До каталогу` веде до `Categories`
- Натискання картки категорії веде до майбутнього екрана каталогу
- Натискання кошика веде до майбутнього drawer кошика
- Нижня навігація на mobile закріплена знизу

Живим еталоном макета є Blade-шаблон `resources/views/store.blade.php` та стилі `resources/css/app.css`.
