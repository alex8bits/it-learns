// Money is stored in minor units (kopecks/cents) and rendered with a fixed
// two-digit fraction: `69050` -> "690,50 ₽".
export const formatMoney = (minor, currency = 'RUB') => {
    const value = (minor / 100).toLocaleString('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return `${value} ${currency === 'RUB' ? '₽' : currency}`;
};
