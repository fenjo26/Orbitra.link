export const SegmentedControl = ({ options, value, onChange, ariaLabel }) => (
    <div
        className="p-1 rounded-xl flex gap-1 border"
        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
        role="radiogroup"
        aria-label={ariaLabel}
    >
        {options.map((option) => {
            const isActive = value === option.value;
            const Icon = option.icon;

            return (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={isActive}
                    onClick={() => onChange(option.value)}
                    className="flex-1 py-2 px-3 text-xs font-semibold rounded-lg transition-all flex items-center justify-center gap-1.5"
                    style={{
                        backgroundColor: isActive ? 'var(--color-primary)' : 'transparent',
                        color: isActive ? '#ffffff' : 'var(--color-text-secondary)',
                        boxShadow: isActive ? '0 2px 8px rgba(0, 0, 0, 0.15)' : 'none'
                    }}
                >
                    {Icon && <Icon className="w-3.5 h-3.5" />}
                    <span>{option.label}</span>
                </button>
            );
        })}
    </div>
);

export default SegmentedControl;
