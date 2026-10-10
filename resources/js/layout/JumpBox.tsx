import { useState, type KeyboardEvent } from 'react';
import { useNavigate } from 'react-router';
import { useDomainNames } from './useNavData';

export function JumpBox({ className }: { className?: string }) {
    const [text, setText] = useState('');
    const names = useDomainNames();
    const navigate = useNavigate();

    function go() {
        const q = text.trim().toLowerCase();
        if (q === '') return;
        const list = names.data ?? [];
        const hit =
            list.find((d) => d.name === q || d.unicode_name === q) ??
            list.find((d) => d.name.startsWith(q) || d.unicode_name.startsWith(q));
        void navigate(hit ? `/domains/${hit.name}/mailboxes` : `/addresses?q=${encodeURIComponent(q)}`);
        setText('');
    }

    function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'Enter') {
            e.preventDefault();
            go();
        }
    }

    return (
        <div className={`flex h-[42px] items-center gap-2 rounded-md border border-line-strong bg-white px-3 ${className ?? ''}`}>
            <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="flex-none text-body">
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-3.5-3.5" />
            </svg>
            <input
                type="search"
                aria-label="Jump to domain or address"
                placeholder="Jump to domain or address"
                value={text}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={onKeyDown}
                className="min-w-0 flex-1 bg-transparent text-[13.5px] outline-none"
            />
        </div>
    );
}
