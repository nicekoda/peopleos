import { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
import ErrorMessage from '@/Components/ErrorMessage';

interface BaseFieldProps {
    label: string;
    name: string;
    error?: string;
    required?: boolean;
}

type InputFieldProps = BaseFieldProps & InputHTMLAttributes<HTMLInputElement>;

export function InputField({ label, name, error, required, className = '', ...props }: InputFieldProps) {
    return (
        <div>
            <label htmlFor={name} className="block text-sm font-medium text-slate-700">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            <input
                id={name}
                name={name}
                required={required}
                className={`mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm ${className}`}
                {...props}
            />
            <ErrorMessage message={error} />
        </div>
    );
}

type SelectFieldProps = BaseFieldProps & SelectHTMLAttributes<HTMLSelectElement> & { children: ReactNode };

export function SelectField({ label, name, error, required, className = '', children, ...props }: SelectFieldProps) {
    return (
        <div>
            <label htmlFor={name} className="block text-sm font-medium text-slate-700">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            <select
                id={name}
                name={name}
                required={required}
                className={`mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm ${className}`}
                {...props}
            >
                {children}
            </select>
            <ErrorMessage message={error} />
        </div>
    );
}
