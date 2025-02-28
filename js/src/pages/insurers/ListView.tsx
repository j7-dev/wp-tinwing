import { List, useTable, EditButton, DeleteButton } from '@refinedev/antd';
import { Space, Table } from 'antd';
import { DataType, ZDataType } from './types';
import { safeParse } from 'utils';

export const ListView: React.FC = () => {
    const { tableProps } = useTable<DataType>();
    // console.log('🚀 ~ tableProps:', tableProps);

    const parsedTableProps = safeParse<DataType>({
        tableProps,
        ZDataType,
    });

    return (
        <List createButtonProps={{ type: 'primary' }}>
            <Table {...parsedTableProps} rowKey="id" size="middle">
                <Table.Column width={120} dataIndex="insurerNumber" title="Insurer No." />

                <Table.Column dataIndex="name" title="Name" />

                <Table.Column dataIndex="paymentRate" title="Payment Rate" render={(p: number) => `${p}%`} />

                <Table.Column
                    width={120}
                    dataIndex="id"
                    title=""
                    render={(id) => {
                        return (
                            <>
                                <Space>
                                    <EditButton type="primary" hideText shape="circle" size="small" recordItemId={id} />
                                    <DeleteButton type="primary" danger hideText shape="circle" size="small" recordItemId={id} />
                                </Space>
                            </>
                        );
                    }}
                />
            </Table>
        </List>
    );
};
