// WordPress dependencies

const {
	map,
	defaults,
	upperFirst,
	isFunction,
	isPlainObject,
	every,
	pick,
} = lodash;

const { BaseControl, Tooltip, Button, ButtonGroup } = wp.components;

// Internal dependencies

import { getKey, mergeClasses } from './../utils.js';
import ConditionalWrap from './conditional-wrap.js';

// Select Item Component

const cname = 'zukit-select-item';
const buttonPossibleProps = [
	'href', 'target', 'disabled', 'icon', 'iconSize', 'shortcut', 'onMouseDown',
	'isPrimary', 'isTertiary', 'isPressed', 'isBusy', 'isDefault', 'isLink', 'isDestructive',
];

const SelectItemControl = ({
		className,
		columns = 2,
		isSecondary = true,
		isSmall = true,
		withoutControl,				// if true the control will be wrapped in regular <div>, otherwise in <BaseControl>
		fillMissing,				// if true then 'empty & disabled' items will be added to the required number based on the number of columns
		recap,						// maybe true (then default options will be used) or object with options (label, value, style, isDisabled)
		options,					// array of object with keys 'value' and 'label' [{ label: 'Name', value: 2 }]
									// could be array of values - then it will be transformed to array of objects
		selectedItem,				// value of selected item -> should be the same as in options
		transformValue,				// if function -> then will be used to transform value to React component
		beforeItem,					// if presented will be added before elements from options
		afterItem,					// if presented will be added after elements from options
		label,						// will be used as label and aria-label for ButtonGroup
		help,						// will be used as help string under the control
		buttonStyle,				// added as style to each Button
		buttonClass,				// added as class to each Button
		withLabels,					// if true -> Buttons will be with labels (from options array)
		withTooltip,				// if true -> Buttons will be with Toopltip (from options array)
		onClick,
		...additionalProps			// all additional props go to Button
}) => {

	// fill 'missing' items with 'empty' slots if requested
	const missingCount = fillMissing ? Math.ceil(options.length/columns) * columns - options.length : 0;
	const missing = Array(missingCount).fill().map((_, i) => ({ value: `slot${i}`, isDisabled: true, isSlot: true }));

	const makeItem = ({ label, value, style, isDisabled, isSlot }) => (
		<ConditionalWrap
			condition={ withTooltip }
			wrap={ Tooltip }
			text={ label }
			key={ getKey(value, label) }
		>
			<div key={ getKey(value, label) } className={ mergeClasses(
				`${cname}__button-wrapper`,
				`${cname}__${ value }`,
				{
					'is-selected': selectedItem === value && !isDisabled,
					'is-disabled': isDisabled,
					'is-slot': isSlot,
				})
			}>
				<Button
					className={ mergeClasses(
						`${cname}__button`,
						buttonClass,
						`${cname}__${ value }`,
						{['is-selected']: selectedItem === value && !isDisabled }
					) }
					isSecondary={ isSecondary }
					isSmall={ isSmall }
					onClick={ () => isDisabled ? false : onClick(value) }
					style={ style || buttonStyle  }
					{ ...pick(additionalProps, buttonPossibleProps) }
				>
					{ isSlot ? null : (isFunction(transformValue) ? transformValue(value, label, style) : value) }
				</Button>
				{ !isSlot && withLabels &&
					<div className="block-editor-block-styles__item-label">
						{ label }
					</div>
				}
			</div>
		</ConditionalWrap>
	);

	const defaultRecapOptions = { label: upperFirst(selectedItem), value: selectedItem, style: null, isDisabled: true };
	const recapOptions = isPlainObject(recap) ? defaults(recap, defaultRecapOptions) : defaultRecapOptions;
	const selectOptions = every(options, o => isPlainObject(o)) ? options : map(options, o => ({ label: upperFirst(o), value: o }));

	return (
		<ConditionalWrap
			condition={ !withoutControl }
			elseDiv
			wrap={ BaseControl }
			className={ mergeClasses(cname, `__${columns}columns`, 'components-base-control', { '__recap': recap }, className) }
			label={ label }
			help={ help }
		>
			<ButtonGroup aria-label={ label }>
				{ beforeItem }
				{ recap && makeItem(recapOptions) }
				{ map(selectOptions, makeItem) }
				{ map(missing, makeItem) }
				{ afterItem }
			</ButtonGroup>
		</ConditionalWrap>
	);
}

export default SelectItemControl;
